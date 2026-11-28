<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Mise à jour de la base sans SSH ni Cron (hébergement mutualisé).
 *
 * Désactivée tant que DEPLOY_TOKEN (32 caractères minimum) n'est pas défini dans .env.
 * Appel : https://domaine/maintenance/migrate/{DEPLOY_TOKEN}
 * - exécute les migrations en attente ;
 * - crée les données de départ (permissions, entreprise, compte propriétaire) uniquement
 *   si aucun utilisateur n'existe encore.
 * Après usage, retirer ou changer DEPLOY_TOKEN.
 */
class DeployController extends Controller
{
    public function migrate(Request $request, string $token): Response
    {
        $expected = (string) config('app.deploy_token');

        if (strlen($expected) < 32 || ! hash_equals($expected, $token)) {
            abort(404);
        }

        $output = [];
        try {
            Artisan::call('migrate', ['--force' => true]);
            $output[] = trim(Artisan::output());

            if (! Schema::hasTable('users') || User::query()->doesntExist()) {
                Artisan::call('db:seed', ['--force' => true]);
                $output[] = trim(Artisan::output()) ?: 'Données de départ créées.';
            } else {
                $output[] = 'Données de départ déjà présentes : rien à créer.';
            }

            Artisan::call('optimize:clear');
            $output[] = 'Caches vidés.';
            $status = 200;
        } catch (Throwable $e) {
            report($e);
            $output[] = 'ERREUR : '.$e->getMessage();
            $status = 500;
        }

        return response(implode("\n\n", array_filter($output))."\n", $status)
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-store');
    }
}
