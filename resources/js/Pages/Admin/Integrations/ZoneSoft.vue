<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmAction, showErrorToast, showSuccessToast } from '@/lib/swal';
import axios from 'axios';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface ApplicationData {
    id: number;
    name: string;
    base_url: string;
    app_key: string;
    has_secret: boolean;
    has_usable_secret: boolean;
    requires_secret_reconfiguration: boolean;
    is_active: boolean;
}

interface ClientData {
    id: number;
    name: string;
    business_name: string | null;
}

interface EventItem {
    id: number;
    title: string;
}

interface MachineItem {
    id: number;
    client_id: number;
    client_name: string;
    zs_client_id: string;
    license: string | null;
    store_id: number;
    store_label: string | null;
    permissions: string | null;
    is_active: boolean;
    last_validated_at: string | null;
    last_error: string | null;
    events: EventItem[];
}

interface StoreOption {
    id: number;
    label: string;
    display_label: string;
}

const props = defineProps<{
    application: ApplicationData | null;
    clients: ClientData[];
    defaultMachinePermissions: string;
    machines: MachineItem[];
}>();

const search = ref('');
const clientFilter = ref<number | ''>('');
const editingMachineId = ref<number | null>(null);
const discoveringStores = ref(false);
const validatingMachines = ref(false);
const storeValidationMessage = ref('');

const applicationForm = useForm({
    name: props.application?.name ?? 'Portal Contactodigital',
    base_url: props.application?.base_url ?? 'https://api.zonesoft.org/v3',
    app_key: props.application?.app_key ?? '',
    app_secret: '',
    is_active: props.application?.is_active ?? true,
});

const machineForm = useForm({
    client_id: '' as number | '',
    license: '',
    zs_client_id: '',
    store_id: '' as number | '',
    store_label: '',
    is_active: true,
});

const applicationNeedsSecret = computed(() => (
    !props.application
    || !props.application.has_secret
    || props.application.requires_secret_reconfiguration
));
const hasConfiguredApplication = computed(() => (
    props.application?.has_usable_secret
    && props.application.is_active
));
const filteredMachines = computed(() => {
    const term = search.value.trim().toLocaleLowerCase('pt-PT');

    return props.machines.filter((machine) => {
        if (clientFilter.value !== '' && machine.client_id !== clientFilter.value) {
            return false;
        }

        if (!term) {
            return true;
        }

        return [
            machine.client_name,
            machine.license,
            machine.zs_client_id,
            String(machine.store_id),
            machine.store_label,
            ...machine.events.map((event) => event.title),
        ].some((value) => value?.toLocaleLowerCase('pt-PT').includes(term));
    });
});
const licenseCount = computed(() => new Set(
    props.machines.map((machine) => machine.license).filter(Boolean),
).size);

const integrationRoute = (action: string, machineId?: number) => route(
    `admin.integrations.zonesoft.${action}`,
    machineId,
);

const formatDateTime = (date: string | null) => date
    ? new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(date))
    : 'Sem validação';

const resetMachineForm = () => {
    editingMachineId.value = null;
    machineForm.reset();
    machineForm.clearErrors();
    storeValidationMessage.value = '';
};

const editMachine = (machine: MachineItem) => {
    editingMachineId.value = machine.id;
    machineForm.client_id = machine.client_id;
    machineForm.license = machine.license ?? '';
    machineForm.zs_client_id = machine.zs_client_id;
    machineForm.store_id = machine.store_id;
    machineForm.store_label = machine.store_label ?? '';
    machineForm.is_active = machine.is_active;
    machineForm.clearErrors();
    storeValidationMessage.value = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const saveApplication = () => {
    applicationForm.post(integrationRoute('application.save'), {
        preserveScroll: true,
        onSuccess: () => {
            applicationForm.app_secret = '';
            void showSuccessToast('Aplicação ZoneSoft guardada com sucesso.');
        },
        onError: () => void showErrorToast('Não foi possível guardar a aplicação ZoneSoft.'),
    });
};

const discoverStore = async () => {
    storeValidationMessage.value = '';
    machineForm.clearErrors();

    if (!machineForm.zs_client_id || machineForm.store_id === '') {
        void showErrorToast('Informe o Client ID e o Store ID antes de validar.');
        return;
    }

    discoveringStores.value = true;

    try {
        const response = await axios.post(integrationRoute('discover-stores'), {
            zs_client_id: machineForm.zs_client_id,
        });
        const stores = (response.data.stores ?? []) as StoreOption[];
        const selectedStore = stores.find((store) => store.id === machineForm.store_id);

        if (!selectedStore) {
            throw new Error(`O Store ID ${machineForm.store_id} não foi encontrado neste Client ID.`);
        }

        machineForm.store_label = selectedStore.label;
        storeValidationMessage.value = `Loja validada: ${selectedStore.display_label}`;
    } catch (error: unknown) {
        const message = axios.isAxiosError(error)
            ? (error.response?.data?.message as string | undefined) ?? 'Não foi possível validar a loja.'
            : error instanceof Error ? error.message : 'Não foi possível validar a loja.';
        void showErrorToast(message);
    } finally {
        discoveringStores.value = false;
    }
};

const submitMachine = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            resetMachineForm();
            void showSuccessToast('Integração global guardada com sucesso.');
        },
        onError: () => void showErrorToast('Não foi possível guardar a integração global.'),
    };

    if (editingMachineId.value) {
        machineForm.put(integrationRoute('machines.update', editingMachineId.value), options);
        return;
    }

    machineForm.post(integrationRoute('machines.store'), options);
};

const validateMachines = async () => {
    validatingMachines.value = true;

    try {
        const response = await axios.post(integrationRoute('machines.validate-all'), {
            client_id: clientFilter.value === '' ? null : clientFilter.value,
        });
        const failed = Number(response.data.failed ?? 0);
        const message = String(response.data.message ?? 'Validação concluída.');

        if (failed > 0) {
            void showErrorToast(message);
        } else {
            void showSuccessToast(message);
        }

        router.reload({ only: ['machines'] });
    } catch (error: unknown) {
        const message = axios.isAxiosError(error)
            ? (error.response?.data?.message as string | undefined) ?? 'Não foi possível validar as integrações.'
            : 'Não foi possível validar as integrações.';
        void showErrorToast(message);
    } finally {
        validatingMachines.value = false;
    }
};

const deleteMachine = async (machine: MachineItem) => {
    const confirmed = await confirmAction({
        title: 'Eliminar integração global?',
        text: machine.events.length
            ? 'Este TPA está associado a eventos e não pode ser eliminado.'
            : `A loja ${machine.store_id} será removida do catálogo global.`,
        confirmButtonText: 'Eliminar',
    });

    if (!confirmed || machine.events.length) {
        return;
    }

    router.delete(integrationRoute('machines.destroy', machine.id), {
        preserveScroll: true,
        onSuccess: () => void showSuccessToast('Integração global eliminada.'),
        onError: () => void showErrorToast('Não foi possível eliminar a integração global.'),
    });
};
</script>

<template>
    <Head title="Integrações ZoneSoft" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex w-full flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="dash-page-title">Integrações ZoneSoft</h2>
                    <p class="dash-muted-text">Catálogo global de licenças, Client IDs e TPAs</p>
                </div>
                <Link :href="route('admin.events.index')" class="dash-link-button">Ver eventos</Link>
            </div>
        </template>

        <div class="dash-page space-y-6">
            <section class="grid gap-4 md:grid-cols-3">
                <article class="dash-card">
                    <p class="dash-recent-subtitle">Licenças</p>
                    <p class="mt-2 text-3xl font-bold text-current">{{ licenseCount }}</p>
                </article>
                <article class="dash-card">
                    <p class="dash-recent-subtitle">TPAs globais</p>
                    <p class="mt-2 text-3xl font-bold text-current">{{ props.machines.length }}</p>
                </article>
                <article class="dash-card">
                    <p class="dash-recent-subtitle">Aplicação</p>
                    <p class="mt-2 text-lg font-bold text-current">
                        {{ hasConfiguredApplication ? 'Configurada' : 'Pendente' }}
                    </p>
                </article>
            </section>

            <section class="dash-card space-y-5">
                <div>
                    <h3 class="dash-card-title mb-0">Aplicação ZoneSoft</h3>
                    <p class="dash-recent-subtitle">Credenciais partilhadas por todo o catálogo.</p>
                </div>

                <form class="dash-modal-grid" @submit.prevent="saveApplication">
                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="zs_app_name">Nome</label>
                        <input id="zs_app_name" v-model="applicationForm.name" class="dash-modal-input" required />
                    </div>
                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="zs_base_url">URL base</label>
                        <input id="zs_base_url" v-model="applicationForm.base_url" class="dash-modal-input" type="url" required />
                    </div>
                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="zs_app_key">APP-KEY</label>
                        <input id="zs_app_key" v-model="applicationForm.app_key" class="dash-modal-input" required />
                    </div>
                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="zs_app_secret">APP-SECRET</label>
                        <input
                            id="zs_app_secret"
                            v-model="applicationForm.app_secret"
                            class="dash-modal-input"
                            type="password"
                            :required="applicationNeedsSecret"
                            :placeholder="props.application?.has_secret ? 'Deixe vazio para manter o segredo atual' : ''"
                        />
                        <p class="admin-event-input-hint">O segredo é cifrado e nunca volta a ser exibido.</p>
                    </div>
                    <label class="dash-modal-field-full inline-flex items-center gap-3 text-sm font-medium text-current">
                        <input v-model="applicationForm.is_active" type="checkbox" class="rounded border-current/30" />
                        Aplicação ativa
                    </label>
                    <div class="dash-modal-actions dash-modal-field-full">
                        <button class="dash-action-button dash-action-button-inline" :disabled="applicationForm.processing">
                            Guardar aplicação
                        </button>
                    </div>
                </form>
            </section>

            <section class="dash-card space-y-5">
                <div>
                    <h3 class="dash-card-title mb-0">
                        {{ editingMachineId ? 'Editar integração global' : 'Nova integração global' }}
                    </h3>
                    <p class="dash-recent-subtitle">Cadastre a licença e cada Store ID uma única vez.</p>
                </div>

                <form class="dash-modal-grid" @submit.prevent="submitMachine">
                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="zs_client_owner">Cliente</label>
                        <select id="zs_client_owner" v-model.number="machineForm.client_id" class="dash-modal-input" required>
                            <option value="" disabled>Selecione o cliente</option>
                            <option v-for="client in props.clients" :key="client.id" :value="client.id">
                                {{ client.name }}
                            </option>
                        </select>
                        <p v-if="machineForm.errors.client_id" class="dash-modal-error">{{ machineForm.errors.client_id }}</p>
                    </div>
                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="zs_license">Licença</label>
                        <input id="zs_license" v-model="machineForm.license" class="dash-modal-input" required placeholder="Ex.: LRXUTHVXSU" />
                        <p v-if="machineForm.errors.license" class="dash-modal-error">{{ machineForm.errors.license }}</p>
                    </div>
                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="zs_client_id">Client ID</label>
                        <input id="zs_client_id" v-model="machineForm.zs_client_id" class="dash-modal-input" required />
                        <p v-if="machineForm.errors.zs_client_id" class="dash-modal-error">{{ machineForm.errors.zs_client_id }}</p>
                    </div>
                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="zs_store_id">Store ID</label>
                        <div class="flex gap-3">
                            <input id="zs_store_id" v-model.number="machineForm.store_id" class="dash-modal-input" type="number" min="0" required />
                            <button type="button" class="dash-link-button shrink-0" :disabled="discoveringStores" @click="discoverStore">
                                {{ discoveringStores ? 'A validar...' : 'Validar' }}
                            </button>
                        </div>
                        <p v-if="machineForm.errors.store_id" class="dash-modal-error">{{ machineForm.errors.store_id }}</p>
                        <p v-if="storeValidationMessage" class="admin-event-input-hint text-emerald-500">{{ storeValidationMessage }}</p>
                    </div>
                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="zs_store_label">Nome da loja/TPA</label>
                        <input id="zs_store_label" v-model="machineForm.store_label" class="dash-modal-input" />
                    </div>
                    <label class="dash-modal-field-full inline-flex items-center gap-3 text-sm font-medium text-current">
                        <input v-model="machineForm.is_active" type="checkbox" class="rounded border-current/30" />
                        TPA ativo
                    </label>
                    <div class="dash-modal-actions dash-modal-field-full">
                        <button v-if="editingMachineId" type="button" class="dash-modal-cancel" @click="resetMachineForm">Cancelar edição</button>
                        <button class="dash-action-button dash-action-button-inline" :disabled="machineForm.processing || !hasConfiguredApplication">
                            {{ editingMachineId ? 'Guardar alterações' : 'Adicionar ao catálogo' }}
                        </button>
                    </div>
                </form>
            </section>

            <section class="dash-card space-y-5">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="grid flex-1 gap-3 md:grid-cols-2">
                        <div>
                            <label class="dash-modal-label" for="zs_search">Pesquisar</label>
                            <input id="zs_search" v-model="search" class="dash-modal-input" placeholder="Licença, cliente, Store ID..." />
                        </div>
                        <div>
                            <label class="dash-modal-label" for="zs_client_filter">Filtrar cliente</label>
                            <select id="zs_client_filter" v-model.number="clientFilter" class="dash-modal-input">
                                <option value="">Todos os clientes</option>
                                <option v-for="client in props.clients" :key="client.id" :value="client.id">{{ client.name }}</option>
                            </select>
                        </div>
                    </div>
                    <button type="button" class="dash-link-button" :disabled="validatingMachines || !props.machines.length" @click="validateMachines">
                        {{ validatingMachines ? 'A validar...' : 'Validar lojas' }}
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="admin-clients-table min-w-[1100px]">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Licença</th>
                                <th>Store ID</th>
                                <th>Loja</th>
                                <th>Client ID</th>
                                <th>Eventos</th>
                                <th>Estado</th>
                                <th>Validação</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="machine in filteredMachines" :key="machine.id">
                                <td class="admin-clients-text font-semibold">{{ machine.client_name }}</td>
                                <td class="admin-clients-text">{{ machine.license }}</td>
                                <td class="admin-clients-text">{{ machine.store_id }}</td>
                                <td class="admin-clients-text">{{ machine.store_label || 'Sem nome' }}</td>
                                <td class="admin-clients-text"><span class="block max-w-[11rem] truncate">{{ machine.zs_client_id }}</span></td>
                                <td class="admin-clients-text">
                                    <span v-if="machine.events.length">{{ machine.events.length }} associado{{ machine.events.length === 1 ? '' : 's' }}</span>
                                    <span v-else>Nenhum</span>
                                </td>
                                <td class="admin-clients-text">
                                    <span class="status-pill" :class="machine.is_active ? 'success' : 'neutral'">{{ machine.is_active ? 'Ativo' : 'Inativo' }}</span>
                                </td>
                                <td class="admin-clients-text">
                                    <p>{{ formatDateTime(machine.last_validated_at) }}</p>
                                    <p v-if="machine.last_error" class="mt-1 max-w-xs text-xs text-rose-500">{{ machine.last_error }}</p>
                                </td>
                                <td>
                                    <div class="admin-clients-actions">
                                        <button type="button" class="admin-client-icon-btn" title="Editar" @click="editMachine(machine)">Editar</button>
                                        <button type="button" class="admin-client-icon-btn danger" title="Eliminar" @click="deleteMachine(machine)">Eliminar</button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!filteredMachines.length">
                                <td colspan="9" class="py-8 text-center text-sm"><span class="dash-muted-text">Nenhuma integração encontrada.</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
