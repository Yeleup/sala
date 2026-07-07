<?php

namespace App\Console\Commands;

use App\Models\DereuCompany;
use App\Services\DereuPlatformClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

#[Signature('dereu:provision-company {external_id : Our internal organization id} {--name= : Human-readable company name}')]
#[Description('Provision a company in Dereu and store its credentials (api_key is issued only once)')]
class ProvisionDereuCompany extends Command
{
    public function handle(DereuPlatformClient $client): int
    {
        $externalId = (string) $this->argument('external_id');
        $name = $this->option('name');

        try {
            $result = $client->provisionCompany($externalId, $name);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (RequestException $exception) {
            $this->error(sprintf(
                'Dereu rejected the request (HTTP %d): %s',
                $exception->response->status(),
                $exception->response->json('message') ?? $exception->response->body(),
            ));

            return self::FAILURE;
        }

        $company = DereuCompany::query()->firstOrNew(['external_id' => $externalId]);
        $company->name = $name ?? $company->name ?? $externalId;
        $company->dereu_company_id = $result['dereu_company_id'];
        $company->status = $company->status ?? DereuCompany::STATUS_PROVISIONED;

        if (filled($result['api_key'] ?? null)) {
            $company->api_key = $result['api_key'];
        }

        $company->save();

        $this->info("Company {$externalId} → {$result['dereu_company_id']}.");

        if ($result['already_provisioned'] ?? false) {
            $this->comment('Already provisioned in Dereu: the api_key is not re-issued on repeated calls.');

            if (blank($company->api_key)) {
                $this->warn('No api_key is stored locally. Ask the Dereu operator to re-issue one — sending messages will not work without it.');
            }
        } else {
            $this->info('Company api_key received and stored encrypted.');
        }

        return self::SUCCESS;
    }
}
