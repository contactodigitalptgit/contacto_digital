<script setup lang="ts">
import Modal from '@/Components/Modal.vue';
import { confirmAction, showErrorToast, showSuccessToast } from '@/lib/swal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import axios from 'axios';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface ClientData {
    id: number;
    name: string;
    business_name: string | null;
    email: string | null;
}

interface ApplicationData {
    id: number;
    name: string;
    base_url: string;
    app_key: string;
    has_secret: boolean;
    is_active: boolean;
}

interface MachineItem {
    id: number;
    zs_client_id: string;
    license: string | null;
    store_id: number;
    store_label: string | null;
    permissions: string | null;
    is_active: boolean;
    last_validated_at: string | null;
    last_error: string | null;
}

interface StoreOption {
    id: number;
    label: string;
    display_label: string;
    details: string | null;
    country: string | null;
}

const props = defineProps<{
    client: ClientData;
    application: ApplicationData | null;
    defaultMachinePermissions: string;
    machines: MachineItem[];
}>();

const showMachineModal = ref(false);
const editingMachineId = ref<number | null>(null);
const discoveringStores = ref(false);
const discoveredStores = ref<StoreOption[]>([]);
const storeDiscoveryError = ref('');
const storeValidationSuccess = ref('');
const keepSharedLicense = ref(false);

const applicationForm = useForm({
    name: props.application?.name ?? 'ZoneSoft Principal',
    base_url: props.application?.base_url ?? 'https://api.zonesoft.org/v3',
    app_key: props.application?.app_key ?? '',
    app_secret: '',
    is_active: props.application?.is_active ?? true,
});

const machineForm = useForm({
    zs_client_id: '',
    license: '',
    store_id: '' as number | '',
    store_label: '',
    is_active: true,
});

const hasConfiguredApplication = computed(
    () => props.application !== null && props.application.has_secret && props.application.is_active,
);

const reusableLicense = computed(() => {
    const licenses = [...new Set(
        props.machines
            .map((machine) => machine.license?.trim() ?? '')
            .filter((license) => license !== ''),
    )];

    return licenses.length === 1 ? licenses[0] : '';
});

const canReuseLicense = computed(() => reusableLicense.value !== '');

const formatDateTime = (date: string | null) =>
    date
        ? new Intl.DateTimeFormat('pt-PT', {
              dateStyle: 'medium',
              timeStyle: 'short',
          }).format(new Date(date))
        : 'Sem validação';

const resetStoreValidation = () => {
    discoveredStores.value = [];
    storeDiscoveryError.value = '';
    storeValidationSuccess.value = '';
};

const resetMachineForm = () => {
    editingMachineId.value = null;
    machineForm.reset();
    machineForm.clearErrors();
    resetStoreValidation();
};

const updateSelectedStoreLabel = () => {
    const selectedStore = discoveredStores.value.find((store) => store.id === machineForm.store_id);

    if (selectedStore) {
        machineForm.store_label = selectedStore.label;
    }
};

const applyReusableLicensePreference = () => {
    if (editingMachineId.value || !canReuseLicense.value) {
        return;
    }

    machineForm.license = keepSharedLicense.value ? reusableLicense.value : '';
};

const invalidateStoreValidation = () => {
    resetStoreValidation();
    machineForm.store_label = '';
};

const openCreateMachineModal = () => {
    resetMachineForm();
    machineForm.is_active = true;
    keepSharedLicense.value = canReuseLicense.value;
    applyReusableLicensePreference();
    showMachineModal.value = true;
};

const openEditMachineModal = (machine: MachineItem) => {
    resetMachineForm();
    editingMachineId.value = machine.id;
    machineForm.zs_client_id = machine.zs_client_id;
    machineForm.license = machine.license ?? '';
    machineForm.store_id = machine.store_id;
    machineForm.store_label = machine.store_label ?? '';
    machineForm.is_active = machine.is_active;
    keepSharedLicense.value = false;
    storeValidationSuccess.value = machine.store_label
        ? `Loja atual: Loja ${machine.store_id} - ${machine.store_label}`
        : '';
    showMachineModal.value = true;
};

const closeMachineModal = () => {
    showMachineModal.value = false;
    resetMachineForm();
};

const saveApplication = () => {
    applicationForm.post(route('admin.clients.integrations.application.save', props.client.id), {
        preserveScroll: true,
        onSuccess: () => {
            applicationForm.app_secret = '';
            void showSuccessToast('Aplicação ZoneSoft guardada com sucesso.');
        },
        onError: () => {
            void showErrorToast('Não foi possível guardar a aplicação ZoneSoft.');
        },
    });
};

const discoverStores = async () => {
    machineForm.clearErrors();
    resetStoreValidation();

    if (!machineForm.zs_client_id) {
        machineForm.setError('zs_client_id', 'Informe primeiro o Client ID.');
        return;
    }

    if (machineForm.store_id === '') {
        machineForm.setError('store_id', 'Informe o Store ID que pretende associar.');
        return;
    }

    discoveringStores.value = true;

    try {
        const response = await axios.post(
            route('admin.clients.integrations.discover-stores', props.client.id),
            {
                zs_client_id: machineForm.zs_client_id,
            },
        );

        const stores = (response.data.stores ?? []) as StoreOption[];
        discoveredStores.value = stores;
        const selectedStore = stores.find((store) => store.id === machineForm.store_id);

        if (selectedStore) {
            machineForm.store_label = selectedStore.label;
            storeValidationSuccess.value = `Loja validada: ${selectedStore.display_label}`;
        } else if (stores.length === 0) {
            machineForm.store_label = '';
            storeDiscoveryError.value = 'Nenhuma loja foi encontrada para este Client ID.';
        } else {
            machineForm.store_label = '';
            storeDiscoveryError.value = `O Store ID ${machineForm.store_id} não foi encontrado para este Client ID. Este cliente devolveu ${stores.length} loja(s).`;
        }
    } catch (error: unknown) {
        storeDiscoveryError.value = axios.isAxiosError(error)
            ? (error.response?.data?.message as string | undefined) ||
              'Não foi possível descobrir as lojas disponíveis.'
            : 'Não foi possível descobrir as lojas disponíveis.';
    } finally {
        discoveringStores.value = false;
    }
};

const submitMachine = () => {
    updateSelectedStoreLabel();

    const requestOptions = {
        preserveScroll: true,
        onSuccess: () => {
            closeMachineModal();
            void showSuccessToast('Client ID guardado com sucesso.');
        },
        onError: () => {
            void showErrorToast('Não foi possível guardar o Client ID.');
        },
    };

    if (editingMachineId.value) {
        machineForm.put(
            route('admin.clients.integrations.machines.update', [
                props.client.id,
                editingMachineId.value,
            ]),
            requestOptions,
        );

        return;
    }

    machineForm.post(
        route('admin.clients.integrations.machines.store', props.client.id),
        requestOptions,
    );
};

const deleteMachine = async (machine: MachineItem) => {
    const confirmed = await confirmAction({
        title: 'Remover Client ID?',
        text: `O Client ID ${machine.zs_client_id} será removido deste cliente.`,
        confirmButtonText: 'Remover',
    });

    if (!confirmed) {
        return;
    }

    router.delete(
        route('admin.clients.integrations.machines.destroy', [
            props.client.id,
            machine.id,
        ]),
        {
            preserveScroll: true,
            onSuccess: () => {
                void showSuccessToast('Client ID removido com sucesso.');
            },
            onError: () => {
                void showErrorToast('Não foi possível remover o Client ID.');
            },
        },
    );
};
</script>

<template>
    <Head :title="`Integrações - ${props.client.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex w-full flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="dash-page-title">Integrações ZoneSoft</h2>
                    <p class="dash-muted-text">
                        {{ props.client.name }}{{ props.client.business_name ? ` - ${props.client.business_name}` : '' }}
                    </p>
                </div>

                <Link
                    :href="route('admin.clients.index')"
                    class="dash-link-button"
                >
                    Voltar para clientes
                </Link>
            </div>
        </template>

        <div class="dash-page space-y-6">
            <section class="dash-card space-y-6">
                <div class="dash-recent-header">
                    <div>
                        <h3 class="dash-card-title mb-0">Aplicação ZoneSoft</h3>
                        <p class="dash-recent-subtitle">
                            As credenciais abaixo são partilhadas pelas máquinas deste ambiente.
                        </p>
                    </div>
                    <span
                        class="status-pill"
                        :class="hasConfiguredApplication && applicationForm.is_active ? 'success' : 'neutral'"
                    >
                        {{ hasConfiguredApplication && applicationForm.is_active ? 'Ativa' : 'Pendente' }}
                    </span>
                </div>

                <form class="dash-modal-grid" @submit.prevent="saveApplication">
                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="zonesoft_app_name">
                            Nome da aplicação
                        </label>
                        <input
                            id="zonesoft_app_name"
                            v-model="applicationForm.name"
                            class="dash-modal-input"
                            type="text"
                            required
                        />
                        <p v-if="applicationForm.errors.name" class="dash-modal-error">
                            {{ applicationForm.errors.name }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="zonesoft_app_base_url">
                            URL base
                        </label>
                        <input
                            id="zonesoft_app_base_url"
                            v-model="applicationForm.base_url"
                            class="dash-modal-input"
                            type="url"
                            required
                        />
                        <p v-if="applicationForm.errors.base_url" class="dash-modal-error">
                            {{ applicationForm.errors.base_url }}
                        </p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="zonesoft_app_key">
                            APP-KEY
                        </label>
                        <input
                            id="zonesoft_app_key"
                            v-model="applicationForm.app_key"
                            class="dash-modal-input"
                            type="text"
                            required
                        />
                        <p v-if="applicationForm.errors.app_key" class="dash-modal-error">
                            {{ applicationForm.errors.app_key }}
                        </p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="zonesoft_app_secret">
                            APP-SECRET
                        </label>
                        <input
                            id="zonesoft_app_secret"
                            v-model="applicationForm.app_secret"
                            class="dash-modal-input"
                            type="password"
                            :placeholder="props.application?.has_secret ? 'Deixe em branco para manter o segredo atual' : ''"
                        />
                        <p class="admin-event-input-hint">
                            O segredo fica guardado de forma protegida e não volta a ser exibido em texto aberto.
                        </p>
                        <p v-if="applicationForm.errors.app_secret" class="dash-modal-error">
                            {{ applicationForm.errors.app_secret }}
                        </p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="inline-flex items-center gap-3 text-sm font-medium text-current">
                            <input
                                v-model="applicationForm.is_active"
                                type="checkbox"
                                class="rounded border-current/30"
                            />
                            Aplicação ativa
                        </label>
                    </div>

                    <div class="dash-modal-actions dash-modal-field-full">
                        <button
                            type="submit"
                            class="dash-action-button dash-action-button-inline"
                            :disabled="applicationForm.processing"
                            :class="{ 'opacity-60': applicationForm.processing }"
                        >
                            Guardar aplicação
                        </button>
                    </div>
                </form>
            </section>

            <section class="dash-card space-y-5">
                <div class="dash-recent-header">
                    <div>
                        <h3 class="dash-card-title mb-0">Client IDs do cliente</h3>
                        <p class="dash-recent-subtitle">
                            Cada Client ID representa uma máquina autorizada no mesmo cliente.
                        </p>
                    </div>

                    <button
                        type="button"
                        class="dash-action-button dash-action-button-inline"
                        :disabled="!hasConfiguredApplication"
                        :class="{ 'opacity-60': !hasConfiguredApplication }"
                        @click="openCreateMachineModal"
                    >
                        Novo Client ID
                    </button>
                </div>

                <div v-if="!hasConfiguredApplication" class="event-dashboard-empty">
                    Configure primeiro a aplicação ZoneSoft para começar a cadastrar Client IDs.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="admin-clients-table">
                        <thead>
                            <tr>
                                <th>Client ID</th>
                                <th>Licença</th>
                                <th>Store ID</th>
                                <th>Loja</th>
                                <th>Permissões</th>
                                <th>Status</th>
                                <th>Última validação</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="machine in props.machines" :key="machine.id">
                                <td class="admin-clients-text">
                                    <p class="font-semibold text-current">{{ machine.zs_client_id }}</p>
                                    <p v-if="machine.last_error" class="admin-event-report-empty">
                                        {{ machine.last_error }}
                                    </p>
                                </td>
                                <td class="admin-clients-text">
                                    {{ machine.license || 'Sem licença' }}
                                </td>
                                <td class="admin-clients-text">
                                    {{ machine.store_id }}
                                </td>
                                <td class="admin-clients-text">
                                    {{ machine.store_label || 'Loja sem nome' }}
                                </td>
                                <td class="admin-clients-text">
                                    {{ machine.permissions || 'API' }}
                                </td>
                                <td class="admin-clients-text">
                                    <span
                                        class="status-pill"
                                        :class="machine.is_active ? 'success' : 'neutral'"
                                    >
                                        {{ machine.is_active ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </td>
                                <td class="admin-clients-text">
                                    {{ formatDateTime(machine.last_validated_at) }}
                                </td>
                                <td>
                                    <div class="admin-clients-actions">
                                        <button
                                            type="button"
                                            class="admin-client-icon-btn"
                                            title="Editar Client ID"
                                            aria-label="Editar Client ID"
                                            @click="openEditMachineModal(machine)"
                                        >
                                            <svg
                                                class="h-4 w-4"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                            >
                                                <path d="M12 20h9" />
                                                <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
                                            </svg>
                                        </button>

                                        <button
                                            type="button"
                                            class="admin-client-icon-btn danger"
                                            title="Remover Client ID"
                                            aria-label="Remover Client ID"
                                            @click="deleteMachine(machine)"
                                        >
                                            <svg
                                                class="h-4 w-4"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                            >
                                                <path d="M3 6h18" />
                                                <path d="M8 6V4h8v2" />
                                                <path d="M19 6l-1 14H6L5 6" />
                                                <path d="M10 11v6M14 11v6" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <tr v-if="!props.machines.length">
                                <td colspan="8" class="py-8 text-center text-sm">
                                    <span class="dash-muted-text">
                                        Nenhum Client ID registado para este cliente.
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <Modal
            :show="showMachineModal"
            max-width="2xl"
            @close="closeMachineModal"
        >
            <form class="dash-modal" @submit.prevent="submitMachine">
                <div class="dash-modal-header">
                    <div>
                        <h3 class="dash-modal-title">
                            {{ editingMachineId ? 'Editar Client ID' : 'Novo Client ID' }}
                        </h3>
                        <p class="admin-event-modal-subtitle">
                            Cliente: {{ props.client.name }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="dash-modal-close"
                        @click="closeMachineModal"
                    >
                        <svg
                            class="h-5 w-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M18 6L6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="dash-modal-grid">
                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="machine_client_id">
                            Client ID
                        </label>
                        <input
                            id="machine_client_id"
                            v-model="machineForm.zs_client_id"
                            class="dash-modal-input"
                            type="text"
                            required
                            @input="invalidateStoreValidation"
                        />
                        <p v-if="machineForm.errors.zs_client_id" class="dash-modal-error">
                            {{ machineForm.errors.zs_client_id }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="machine_license">
                            Licença
                        </label>
                        <input
                            id="machine_license"
                            v-model="machineForm.license"
                            class="dash-modal-input"
                            :class="{ 'opacity-70': !editingMachineId && keepSharedLicense }"
                            type="text"
                            :readonly="!editingMachineId && keepSharedLicense"
                        />
                        <label
                            v-if="!editingMachineId && canReuseLicense"
                            class="mt-3 inline-flex items-center gap-3 text-sm font-medium text-current"
                        >
                            <input
                                v-model="keepSharedLicense"
                                type="checkbox"
                                class="rounded border-current/30"
                                @change="applyReusableLicensePreference"
                            />
                            Manter a mesma licença ({{ reusableLicense }})
                        </label>
                        <p class="admin-event-input-hint">
                            Se a licença for a mesma, basta trocar o Client ID e o Store ID desta máquina.
                        </p>
                        <p v-if="machineForm.errors.license" class="dash-modal-error">
                            {{ machineForm.errors.license }}
                        </p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <div class="flex flex-col gap-3 md:flex-row md:items-end">
                            <div class="flex-1">
                                <label class="dash-modal-label" for="machine_store_id">
                                    Store ID
                                </label>
                                <input
                                    id="machine_store_id"
                                    v-model.number="machineForm.store_id"
                                    class="dash-modal-input"
                                    type="number"
                                    min="0"
                                    required
                                    placeholder="Ex.: 101"
                                    @input="invalidateStoreValidation"
                                />
                            </div>

                            <button
                                type="button"
                                class="dash-link-button"
                                :disabled="discoveringStores"
                                @click="discoverStores"
                            >
                                {{ discoveringStores ? 'A validar...' : 'Validar loja' }}
                            </button>
                        </div>
                        <p class="admin-event-input-hint">
                            Informe o Store ID da máquina e valide para confirmar que ele pertence ao Client ID informado.
                        </p>
                        <p v-if="storeValidationSuccess" class="admin-event-input-hint text-emerald-400">
                            {{ storeValidationSuccess }}
                        </p>
                        <p v-if="storeDiscoveryError" class="dash-modal-error">
                            {{ storeDiscoveryError }}
                        </p>
                        <p v-if="machineForm.errors.store_id" class="dash-modal-error">
                            {{ machineForm.errors.store_id }}
                        </p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="machine_store_label">
                            Nome da loja
                        </label>
                        <input
                            id="machine_store_label"
                            v-model="machineForm.store_label"
                            class="dash-modal-input"
                            type="text"
                            placeholder="Preenchido ao validar a loja ou editável manualmente"
                        />
                        <p v-if="machineForm.errors.store_label" class="dash-modal-error">
                            {{ machineForm.errors.store_label }}
                        </p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="machine_permissions">
                            Permissões
                        </label>
                        <input
                            id="machine_permissions"
                            class="dash-modal-input"
                            type="text"
                            :value="props.defaultMachinePermissions"
                            readonly
                        />
                        <p class="admin-event-input-hint">
                            Este nível de permissão é fixo para todos os Client IDs desta integração.
                        </p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="inline-flex items-center gap-3 text-sm font-medium text-current">
                            <input
                                v-model="machineForm.is_active"
                                type="checkbox"
                                class="rounded border-current/30"
                            />
                            Client ID ativo
                        </label>
                    </div>
                </div>

                <div class="dash-modal-actions">
                    <button
                        type="button"
                        class="dash-modal-cancel"
                        @click="closeMachineModal"
                    >
                        Cancelar
                    </button>

                    <button
                        type="submit"
                        class="dash-action-button dash-action-button-inline"
                        :disabled="machineForm.processing"
                        :class="{ 'opacity-60': machineForm.processing }"
                    >
                        Guardar Client ID
                    </button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
