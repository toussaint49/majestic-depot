<?php

namespace App\Services;

use App\Exceptions\CashSessionException;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Depot;
use App\Models\PaymentAccount;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Caisse journalière d'un dépôt : ouverture → encaissements / apports / retraits →
 * clôture (comptage) → validation par un responsable. Une session validée est figée.
 */
class CashSessionService
{
    public function __construct(private AccountLedger $ledger, private SequenceService $sequences) {}

    public function current(Depot $depot): ?CashSession
    {
        return CashSession::where('depot_id', $depot->id)->where('status', CashSession::OPEN)->first();
    }

    /**
     * Session à laquelle rattacher un encaissement. Si l'entreprise exige une caisse
     * ouverte et qu'il n'y en a pas, l'opération est refusée.
     */
    public function forPayment(Depot $depot): ?CashSession
    {
        $session = $this->current($depot);

        if (! $session && app(CurrentTenant::class)->get()?->require_cash_session) {
            throw new CashSessionException("Aucune caisse ouverte pour « {$depot->name} ». Ouvrez la caisse avant d'encaisser.");
        }

        return $session;
    }

    /** Fond de caisse proposé : espèces détenues selon le grand livre. */
    public function suggestedFloat(): int
    {
        $cash = PaymentAccount::where('kind', 'cash')->where('is_active', true)->orderBy('id')->first();

        return $cash ? $this->ledger->balance($cash) : 0;
    }

    /**
     * Ouvre la caisse. Si le fond compté diffère des espèces attendues, l'écart est
     * enregistré (motif obligatoire) pour que le grand livre reflète le tiroir réel.
     */
    public function open(Depot $depot, int $openingFloat, ?string $reason = null): CashSession
    {
        if ($openingFloat < 0) {
            throw new CashSessionException('Le fond de caisse ne peut pas être négatif.');
        }

        return DB::transaction(function () use ($depot, $openingFloat, $reason) {
            Depot::whereKey($depot->id)->lockForUpdate()->first();
            if ($this->current($depot)) {
                throw new CashSessionException("Une caisse est déjà ouverte pour « {$depot->name} ».");
            }
            if (CashSession::where('depot_id', $depot->id)->where('status', CashSession::CLOSED)->exists()) {
                throw new CashSessionException('La clôture précédente doit être validée avant d\'ouvrir une nouvelle caisse.');
            }

            $expected = $this->suggestedFloat();
            $difference = $openingFloat - $expected;
            if ($difference !== 0 && trim((string) $reason) === '') {
                throw new CashSessionException(sprintf(
                    'Le fond saisi (%s) diffère des espèces attendues (%s). Indiquez un motif.',
                    number_format($openingFloat, 0, ',', ' '), number_format($expected, 0, ',', ' '),
                ));
            }

            $session = CashSession::create([
                'depot_id' => $depot->id,
                'number' => $this->sequences->next('cash_session'),
                'opening_float' => $openingFloat,
                'opened_by' => auth()->id(),
                'opened_at' => now(),
            ]);

            if ($difference !== 0) {
                $cash = PaymentAccount::where('kind', 'cash')->where('is_active', true)->orderBy('id')->firstOrFail();
                // Écart constaté avant toute opération : hors session, pour ne pas fausser le théorique.
                $this->ledger->post($cash, $difference, 'cash_difference', $session, "Écart à l'ouverture {$session->number} : $reason", now());
            }

            return $session;
        });
    }

    public function deposit(CashSession $session, PaymentAccount $account, int $amount, string $reason): CashMovement
    {
        return $this->move($session, $account, $amount, $reason, CashMovement::DEPOSIT);
    }

    public function withdraw(CashSession $session, PaymentAccount $account, int $amount, string $reason): CashMovement
    {
        $expected = $this->expected($session)[$account->id] ?? 0;
        if ($amount > $expected) {
            throw new CashSessionException(sprintf('Retrait impossible : seulement %s FCFA attendus sur « %s ».', number_format($expected, 0, ',', ' '), $account->name));
        }

        return $this->move($session, $account, $amount, $reason, CashMovement::WITHDRAWAL);
    }

    /**
     * Solde théorique par compte pour la session.
     * Espèces : fond de caisse + écritures de la session. Autres comptes : écritures de la session.
     *
     * @return array<int, int> payment_account_id => montant
     */
    public function expected(CashSession $session): array
    {
        $expected = $session->transactions()->selectRaw('payment_account_id, sum(amount) as total')
            ->groupBy('payment_account_id')->pluck('total', 'payment_account_id')->map(fn ($v) => (int) $v)->all();

        $cash = PaymentAccount::where('kind', 'cash')->where('is_active', true)->orderBy('id')->first();
        if ($cash) {
            $expected[$cash->id] = ($expected[$cash->id] ?? 0) + $session->opening_float;
        }
        ksort($expected);

        return $expected;
    }

    /**
     * Clôture : le caissier saisit ce qu'il a compté (espèces) ou relevé (MoMo, OM).
     *
     * @param  array<int, int>  $counted  payment_account_id => montant compté
     */
    public function close(CashSession $session, array $counted, ?string $notes = null): CashSession
    {
        return DB::transaction(function () use ($session, $counted, $notes) {
            $session = CashSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (! $session->isOpen()) {
                throw new CashSessionException('Cette caisse n\'est pas ouverte.');
            }

            $expected = $this->expected($session);
            foreach (array_keys($expected + $counted) as $accountId) {
                if (! array_key_exists($accountId, $counted)) {
                    $name = PaymentAccount::find($accountId)?->name;
                    throw new CashSessionException("Saisissez le montant compté pour « $name ».");
                }
                $c = (int) $counted[$accountId];
                if ($c < 0) {
                    throw new CashSessionException('Un montant compté ne peut pas être négatif.');
                }
                $e = $expected[$accountId] ?? 0;
                $session->counts()->create(['payment_account_id' => $accountId, 'expected' => $e, 'counted' => $c, 'difference' => $c - $e]);
            }

            $session->forceFill([
                'status' => CashSession::CLOSED,
                'closed_by' => auth()->id(),
                'closed_at' => now(),
                'notes' => $notes,
            ])->save();

            return $session;
        });
    }

    /**
     * Validation par un responsable : la session est figée et chaque écart est passé
     * en écriture « Écart de caisse ». Toute correction ultérieure passe par une
     * nouvelle opération dans une autre session.
     */
    public function validate(CashSession $session): CashSession
    {
        return DB::transaction(function () use ($session) {
            $session = CashSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status !== CashSession::CLOSED) {
                throw new CashSessionException('Seule une caisse clôturée peut être validée.');
            }

            foreach ($session->counts()->with('account')->get() as $count) {
                if ($count->difference !== 0) {
                    $this->ledger->post($count->account, $count->difference, 'cash_difference', $session, "Écart de clôture {$session->number}", now());
                }
            }

            $session->forceFill([
                'status' => CashSession::VALIDATED,
                'validated_by' => auth()->id(),
                'validated_at' => now(),
            ])->save();

            return $session;
        });
    }

    private function move(CashSession $session, PaymentAccount $account, int $amount, string $reason, string $type): CashMovement
    {
        if ($amount <= 0) {
            throw new CashSessionException('Le montant doit être positif.');
        }
        if (trim($reason) === '') {
            throw new CashSessionException('Le motif est obligatoire.');
        }

        return DB::transaction(function () use ($session, $account, $amount, $reason, $type) {
            $session = CashSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (! $session->isOpen()) {
                throw new CashSessionException('Cette caisse n\'est plus ouverte.');
            }

            $movement = CashMovement::create([
                'cash_session_id' => $session->id,
                'payment_account_id' => $account->id,
                'type' => $type,
                'amount' => $amount,
                'reason' => $reason,
                'user_id' => auth()->id(),
                'occurred_at' => now(),
            ]);

            $signed = $type === CashMovement::DEPOSIT ? $amount : -$amount;
            $this->ledger->post($account, $signed, $type === CashMovement::DEPOSIT ? 'cash_deposit' : 'cash_withdrawal', $movement, CashMovement::TYPES[$type]." : $reason", now(), $session);

            return $movement;
        });
    }
}
