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

interface ImportPreviewRow {
    line: number;
    zs_client_id: string;
    license: string;
    store_id: number;
    permissions: string;
    status: 'new' | 'existing' | 'conflict' | 'invalid';
    message: string;
}

interface ImportPreview {
    source_application: { id: string; name: string };
    client: { id: number; name: string };
    summary: {
        total: number;
        new: number;
        existing: number;
        conflicts: number;
        invalid: number;
    };
    can_import: boolean;
    rows: ImportPreviewRow[];
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
const importClientId = ref<number | ''>(props.clients.length === 1 ? props.clients[0].id : '');
const importPayload = ref('');
const importPreview = ref<ImportPreview | null>(null);
const previewingImport = ref(false);
const importingMachines = ref(false);

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

const importStatusLabel = (status: ImportPreviewRow['status']) => ({
    new: 'Nova',
    existing: 'Já existe',
    conflict: 'Conflito',
    invalid: 'Inválida',
}[status]);

const importStatusClass = (status: ImportPreviewRow['status']) => ({
    new: 'success',
    existing: 'neutral',
    conflict: 'warning',
    invalid: 'warning',
}[status]);

const clearImportPreview = () => {
    importPreview.value = null;
};

const parseImportPayload = (): Record<string, unknown> => {
    if (importClientId.value === '') {
        throw new Error('Selecione o cliente que será proprietário das integrações.');
    }

    if (!importPayload.value.trim()) {
        throw new Error('Cole primeiro o lote copiado pela extensão.');
    }

    const parsed = JSON.parse(importPayload.value) as unknown;

    if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') {
        throw new Error('O lote deve ser um objeto JSON exportado pela extensão.');
    }

    return parsed as Record<string, unknown>;
};

const importErrorMessage = (error: unknown, fallback: string) => {
    if (!axios.isAxiosError(error)) {
        return error instanceof Error ? error.message : fallback;
    }

    const errors = error.response?.data?.errors as Record<string, string[]> | undefined;
    const firstValidationError = errors ? Object.values(errors).flat()[0] : undefined;

    return firstValidationError
        ?? (error.response?.data?.message as string | undefined)
        ?? fallback;
};

const previewMachineImport = async () => {
    clearImportPreview();
    previewingImport.value = true;

    try {
        const payload = parseImportPayload();
        const response = await axios.post(integrationRoute('machines.import.preview'), {
            client_id: importClientId.value,
            payload,
        });
        importPreview.value = response.data as ImportPreview;
    } catch (error: unknown) {
        void showErrorToast(importErrorMessage(error, 'Não foi possível pré-visualizar o lote.'));
    } finally {
        previewingImport.value = false;
    }
};

const importMachines = async () => {
    if (!importPreview.value?.can_import) {
        return;
    }

    const confirmed = await confirmAction({
        title: 'Importar integrações globais?',
        text: `${importPreview.value.summary.new} novas integrações serão adicionadas a ${importPreview.value.client.name}.`,
        confirmButtonText: 'Importar lote',
    });

    if (!confirmed) {
        return;
    }

    importingMachines.value = true;

    try {
        const payload = parseImportPayload();
        const response = await axios.post(integrationRoute('machines.import.store'), {
            client_id: importClientId.value,
            payload,
        });
        importPayload.value = '';
        clearImportPreview();
        void showSuccessToast(String(response.data.message ?? 'Lote importado com sucesso.'));
        router.reload({ only: ['machines'] });
    } catch (error: unknown) {
        clearImportPreview();
        void showErrorToast(importErrorMessage(error, 'Não foi possível importar o lote.'));
    } finally {
        importingMachines.value = false;
    }
};

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
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h3 class="dash-card-title mb-0">Importar em massa</h3>
                        <p class="dash-recent-subtitle">
                            Na extensão ZoneSoft, use “Copiar lote para plataforma” e cole o resultado aqui.
                        </p>
                    </div>
                    <span class="status-pill neutral">Sem APP-KEY ou APP-SECRET</span>
                </div>

                <div class="dash-modal-grid">
                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="zs_import_client">Cliente proprietário</label>
                        <select
                            id="zs_import_client"
                            v-model.number="importClientId"
                            class="dash-modal-input"
                            required
                            @change="clearImportPreview"
                        >
                            <option value="" disabled>Selecione o cliente</option>
                            <option v-for="client in props.clients" :key="client.id" :value="client.id">
                                {{ client.name }}
                            </option>
                        </select>
                    </div>
                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="zs_import_payload">Lote JSON</label>
                        <textarea
                            id="zs_import_payload"
                            v-model="importPayload"
                            class="dash-modal-input min-h-40 font-mono text-xs"
                            placeholder="Cole aqui o lote copiado pela extensão..."
                            @input="clearImportPreview"
                        />
                        <p class="admin-event-input-hint">
                            A pré-visualização não grava dados. São aceitas até 500 integrações por lote.
                        </p>
                    </div>
                    <div class="dash-modal-actions dash-modal-field-full">
                        <button
                            type="button"
                            class="dash-link-button"
                            :disabled="previewingImport || importingMachines || !hasConfiguredApplication"
                            @click="previewMachineImport"
                        >
                            {{ previewingImport ? 'A analisar...' : 'Pré-visualizar lote' }}
                        </button>
                    </div>
                </div>

                <div v-if="importPreview" class="space-y-4 border-t border-current/10 pt-5">
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <article class="rounded-xl border border-current/10 p-4">
                            <p class="dash-recent-subtitle">Total</p>
                            <p class="mt-1 text-2xl font-bold">{{ importPreview.summary.total }}</p>
                        </article>
                        <article class="rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-4">
                            <p class="dash-recent-subtitle">Novas</p>
                            <p class="mt-1 text-2xl font-bold text-emerald-500">{{ importPreview.summary.new }}</p>
                        </article>
                        <article class="rounded-xl border border-current/10 p-4">
                            <p class="dash-recent-subtitle">Já existem</p>
                            <p class="mt-1 text-2xl font-bold">{{ importPreview.summary.existing }}</p>
                        </article>
                        <article class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4">
                            <p class="dash-recent-subtitle">Conflitos</p>
                            <p class="mt-1 text-2xl font-bold text-amber-500">{{ importPreview.summary.conflicts }}</p>
                        </article>
                        <article class="rounded-xl border border-rose-500/30 bg-rose-500/5 p-4">
                            <p class="dash-recent-subtitle">Inválidas</p>
                            <p class="mt-1 text-2xl font-bold text-rose-500">{{ importPreview.summary.invalid }}</p>
                        </article>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-current/10">
                        <table class="admin-clients-table min-w-[850px]">
                            <thead>
                                <tr>
                                    <th>Linha</th>
                                    <th>Licença</th>
                                    <th>Store ID</th>
                                    <th>Client ID</th>
                                    <th>Resultado</th>
                                    <th>Detalhe</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in importPreview.rows.slice(0, 25)" :key="`${row.line}-${row.store_id}`">
                                    <td class="admin-clients-text">{{ row.line }}</td>
                                    <td class="admin-clients-text">{{ row.license }}</td>
                                    <td class="admin-clients-text">{{ row.store_id }}</td>
                                    <td class="admin-clients-text"><span class="block max-w-[12rem] truncate">{{ row.zs_client_id }}</span></td>
                                    <td><span class="status-pill" :class="importStatusClass(row.status)">{{ importStatusLabel(row.status) }}</span></td>
                                    <td class="admin-clients-text">{{ row.message }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-if="importPreview.rows.length > 25" class="admin-event-input-hint">
                        A mostrar as primeiras 25 de {{ importPreview.rows.length }} linhas.
                    </p>
                    <p v-if="!importPreview.can_import" class="dash-modal-error">
                        Corrija os conflitos ou linhas inválidas e faça uma nova pré-visualização.
                    </p>
                    <div class="dash-modal-actions">
                        <button
                            type="button"
                            class="dash-action-button dash-action-button-inline"
                            :disabled="!importPreview.can_import || importingMachines"
                            @click="importMachines"
                        >
                            {{ importingMachines ? 'A importar...' : `Importar ${importPreview.summary.new} integrações` }}
                        </button>
                    </div>
                </div>
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
