<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\ClientZoneSoftMachine;
use App\Models\User;
use App\Models\ZoneSoftApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZoneSoftMachineBulkImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_preview_and_idempotently_import_a_developer_portal_batch(): void
    {
        [$admin, $client, $application] = $this->context();
        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'CLIENT-150',
            'license' => 'LRXUTHVXSU',
            'store_id' => 150,
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
        ]);
        $payload = $this->payload([
            $this->machine('CLIENT-150', 150),
            $this->machine('CLIENT-151', 151),
        ]);

        $this
            ->actingAs($admin)
            ->postJson(route('admin.integrations.zonesoft.machines.import.preview'), [
                'client_id' => $client->id,
                'payload' => $payload,
            ])
            ->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.new', 1)
            ->assertJsonPath('summary.existing', 1)
            ->assertJsonPath('summary.conflicts', 0)
            ->assertJsonPath('can_import', true)
            ->assertJsonPath('rows.0.status', 'existing')
            ->assertJsonPath('rows.1.status', 'new');

        $this
            ->actingAs($admin)
            ->postJson(route('admin.integrations.zonesoft.machines.import.store'), [
                'client_id' => $client->id,
                'payload' => $payload,
            ])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('existing', 1);

        $this->assertDatabaseHas('client_zonesoft_machines', [
            'client_id' => $client->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'CLIENT-151',
            'license' => 'LRXUTHVXSU',
            'store_id' => 151,
            'store_label' => 'Store 151',
            'permissions' => 'API + All document interfaces',
            'is_active' => true,
            'last_validated_at' => null,
        ]);
        $this->assertDatabaseCount('client_zonesoft_machines', 2);

        $this
            ->actingAs($admin)
            ->postJson(route('admin.integrations.zonesoft.machines.import.store'), [
                'client_id' => $client->id,
                'payload' => $payload,
            ])
            ->assertOk()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('existing', 2);

        $this->assertDatabaseCount('client_zonesoft_machines', 2);
    }

    public function test_import_is_blocked_when_license_and_store_belong_to_another_client_id(): void
    {
        [$admin, $client, $application] = $this->context();
        ClientZoneSoftMachine::create([
            'client_id' => $client->id,
            'zonesoft_application_id' => $application->id,
            'zs_client_id' => 'ORIGINAL-CLIENT',
            'license' => 'LRXUTHVXSU',
            'store_id' => 151,
            'is_active' => true,
        ]);
        $payload = $this->payload([$this->machine('DIFFERENT-CLIENT', 151)]);

        $this
            ->actingAs($admin)
            ->postJson(route('admin.integrations.zonesoft.machines.import.preview'), [
                'client_id' => $client->id,
                'payload' => $payload,
            ])
            ->assertOk()
            ->assertJsonPath('summary.conflicts', 1)
            ->assertJsonPath('can_import', false)
            ->assertJsonPath('rows.0.status', 'conflict');

        $this
            ->actingAs($admin)
            ->postJson(route('admin.integrations.zonesoft.machines.import.store'), [
                'client_id' => $client->id,
                'payload' => $payload,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payload']);

        $this->assertDatabaseCount('client_zonesoft_machines', 1);
    }

    public function test_batch_from_another_application_is_rejected(): void
    {
        [$admin, $client] = $this->context();
        $payload = $this->payload([$this->machine('CLIENT-151', 151)]);
        $payload['application']['name'] = 'Outra aplicação';

        $this
            ->actingAs($admin)
            ->postJson(route('admin.integrations.zonesoft.machines.import.preview'), [
                'client_id' => $client->id,
                'payload' => $payload,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['payload.application.name']);

        $this->assertDatabaseCount('client_zonesoft_machines', 0);
    }

    /**
     * @return array{0: User, 1: Client, 2: ZoneSoftApplication}
     */
    private function context(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $clientUser = User::factory()->create(['role' => 'client']);
        $client = Client::create([
            'user_id' => $clientUser->id,
            'name' => 'Fesnima',
            'business_name' => 'Fesnima',
            'address' => 'Lisboa',
            'phone' => '+351 930000001',
            'is_active' => true,
        ]);
        $application = ZoneSoftApplication::create([
            'name' => 'Portal Contactodigital',
            'base_url' => 'https://api.zonesoft.org/v3',
            'app_key' => 'test-app-key',
            'app_secret' => 'test-app-secret',
            'is_active' => true,
        ]);

        return [$admin, $client, $application];
    }

    /**
     * @param  list<array<string, mixed>>  $machines
     * @return array<string, mixed>
     */
    private function payload(array $machines): array
    {
        return [
            'format' => 'contacto-digital-zonesoft-import',
            'version' => 1,
            'application' => [
                'id' => '1450',
                'name' => 'Portal Contactodigital',
            ],
            'machines' => $machines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function machine(string $clientId, int $storeId): array
    {
        return [
            'zs_client_id' => $clientId,
            'license' => 'LRXUTHVXSU',
            'store_id' => $storeId,
            'permissions' => '{"api":3}',
        ];
    }
}
