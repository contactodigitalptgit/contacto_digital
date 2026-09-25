<script setup lang="ts">
import AppSidebarIcon from '@/Components/AppSidebarIcon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { showErrorToast, showSuccessToast } from '@/lib/swal';
import axios from 'axios';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface EventData {
    id: number;
    title: string;
    event_date: string;
    report_starts_at: string | null;
    report_ends_at: string | null;
    requires_explicit_zones: boolean;
}

interface ExcludedDocument {
    doc_type: string | null;
    document_series: string | null;
    document_number: string | null;
    sale_datetime: string | null;
    rows_count: number;
    sales_total: string;
    quantity_total: string;
    reasons: string[];
    products: string[];
}

interface MachineSyncDiagnostics {
    sync_mode: 'full' | 'incremental';
    synced_at: string | null;
    range_start: string;
    range_end: string;
    source_rows_count: number;
    source_sales_total: string;
    in_range_rows_count: number;
    in_range_sales_total: string;
    excluded_rows_count: number;
    excluded_sales_total: string;
    excluded_documents_count: number;
    excluded_documents_omitted: number;
    excluded_documents: ExcludedDocument[];
    published_rows_count: number;
    published_sales_total: string;
    published_matches_in_range: boolean | null;
}

interface ClientData {
    id: number;
    name: string;
}

interface MachineItem {
    id: number;
    zs_client_id: string;
    store_id: number;
    store_label: string | null;
    license: string | null;
    permissions: string | null;
    is_active: boolean;
    last_validated_at: string | null;
    last_error: string | null;
    is_selected: boolean;
    sync_diagnostics: MachineSyncDiagnostics | null;
}

interface MachineSessionStatus {
    status: 'open' | 'closed' | 'unknown';
    label: string;
    message: string;
    session: {
        cash_register: number;
        opened_at: string | null;
        closed_at: string | null;
        opened_by: string | null;
        closed_by: string | null;
        session_id: number | string | null;
        employee_id: number | string | null;
    } | null;
}

interface SyncStatus {
    status: 'idle' | 'processing' | 'completed' | 'failed';
    started_at: string | null;
    completed_at: string | null;
}

type SalesSyncMode = 'complete-documents' | 'document-sales';

const props = defineProps<{
    event: EventData;
    client: ClientData;
    machines: MachineItem[];
    unassigned_machine_ids: number[];
    sync_status: SyncStatus;
}>();

const initialSelectedMachines = props.machines.filter((machine) => machine.is_selected);
const licenses = computed(() => [...new Set(
    props.machines
        .map((machine) => machine.license?.trim() ?? '')
        .filter((license) => license !== ''),
)].sort());
const selectedLicense = ref(
    initialSelectedMachines[0]?.license?.trim()
    || licenses.value[0]
    || '',
);
const activeTab = ref<'catalog' | 'linked'>('catalog');
const selectedMachineIds = ref<number[]>(
    initialSelectedMachines.map((machine) => machine.id),
);
const viewMode = ref<'cards' | 'list'>('cards');
const search = ref('');
const pickerOpen = ref(false);
const pickerSearch = ref('');
const pickerContainer = ref<HTMLElement | null>(null);
const detailMachine = ref<MachineItem | null>(null);
const detailOpen = ref(false);
const sessionStatus = ref<MachineSessionStatus | null>(null);
const loadingSessionStatus = ref(false);
const syncingSalesMode = ref<SalesSyncMode | null>(null);
const validatingMachines = ref(false);
const syncPollerId = ref<number | null>(null);
const refreshingSyncStatus = ref(false);
const form = useForm({ machine_ids: selectedMachineIds.value });

const licenseMachines = computed(() => props.machines.filter(
    (machine) => (machine.license?.trim() ?? '') === selectedLicense.value,
));
const selectedCount = computed(() => selectedMachineIds.value.length);
const pendingZoneCount = computed(() => props.unassigned_machine_ids.length);
const matchesMachineSearch = (machine: MachineItem, normalizedSearch: string) => normalizedSearch === ''
    || [
        machine.store_label,
        machine.store_id.toString(),
        machine.zs_client_id,
    ].some((value) => value?.toLocaleLowerCase('pt-PT').includes(normalizedSearch));

const filteredMachines = computed(() => {
    const normalizedSearch = search.value.trim().toLocaleLowerCase('pt-PT');

    return licenseMachines.value.filter((machine) => matchesMachineSearch(machine, normalizedSearch));
});
const pickerMachines = computed(() => {
    const normalizedSearch = pickerSearch.value.trim().toLocaleLowerCase('pt-PT');

    return [...licenseMachines.value]
        .filter((machine) => matchesMachineSearch(machine, normalizedSearch))
        .sort((left, right) => {
            const leftSelected = selectedMachineIds.value.includes(left.id) ? 1 : 0;
            const rightSelected = selectedMachineIds.value.includes(right.id) ? 1 : 0;

            if (leftSelected !== rightSelected) {
                return rightSelected - leftSelected;
            }

            return left.store_id - right.store_id;
        });
});
const allSelected = computed(() => (
    filteredMachines.value.length > 0
    && filteredMachines.value.every((machine) => selectedMachineIds.value.includes(machine.id))
));
const selectedMachines = computed(() => licenseMachines.value.filter(
    (machine) => selectedMachineIds.value.includes(machine.id),
));
const selectedMachineSummary = computed(() => selectedMachines.value.map((machine) => ({
    ...machine,
    title: machine.store_label || `Store ${machine.store_id}`,
})));

const formatDateTime = (date: string | null) => date
    ? new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(date))
    : 'Ainda não validado';
const formatShortDateTime = (date: string | null) => date
    ? new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(date))
    : 'Sem registo';
const formatMoney = (value: string | number) => new Intl.NumberFormat('pt-PT', {
    style: 'currency',
    currency: 'EUR',
}).format(Number(value));
const exclusionReason = (reason: string) => ({
    before_start: 'antes do início do relatório',
    after_end: 'depois do fim do relatório',
    outside_range: 'fora do período do relatório',
}[reason] ?? 'fora do período do relatório');
const documentLabel = (document: ExcludedDocument) => [
    document.doc_type,
    document.document_series,
    document.document_number,
].filter(Boolean).join(' ') || 'Documento sem número';

const changeLicense = () => {
    selectedMachineIds.value = props.machines
        .filter((machine) => machine.is_selected && machine.license?.trim() === selectedLicense.value)
        .map((machine) => machine.id);
    search.value = '';
    pickerSearch.value = '';
    pickerOpen.value = false;
};

const toggleMachine = (machineId: number) => {
    selectedMachineIds.value = selectedMachineIds.value.includes(machineId)
        ? selectedMachineIds.value.filter((id) => id !== machineId)
        : [...selectedMachineIds.value, machineId];
};

const toggleAllMachines = () => {
    selectedMachineIds.value = allSelected.value
        ? selectedMachineIds.value.filter((id) => !filteredMachines.value.some((machine) => machine.id === id))
        : [...new Set([
            ...selectedMachineIds.value,
            ...filteredMachines.value.filter((machine) => machine.is_active).map((machine) => machine.id),
        ])];
};

const togglePicker = () => {
    pickerOpen.value = !pickerOpen.value;
};

const toggleMachineFromPicker = (machineId: number) => {
    toggleMachine(machineId);
};

const getCurrentPageUrl = () => (
    typeof window === 'undefined'
        ? route('admin.events.tpas.manage', props.event.id)
        : `${window.location.pathname}${window.location.search}`
);

const openMachineDetail = async (machine: MachineItem) => {
    detailMachine.value = machine;
    detailOpen.value = true;
    sessionStatus.value = null;
    loadingSessionStatus.value = true;

    try {
        const response = await axios.post(
            route('admin.events.tpas.session-status', [props.event.id, machine.id]),
        );
        sessionStatus.value = response.data as MachineSessionStatus;
    } catch (error: unknown) {
        const responseMessage = axios.isAxiosError(error)
            ? error.response?.data?.message as string | undefined
            : undefined;

        sessionStatus.value = {
            status: 'unknown',
            label: 'Indisponível',
            message: responseMessage ?? 'Não foi possível consultar o estado da sessão deste TPA.',
            session: null,
        };
    } finally {
        loadingSessionStatus.value = false;
    }
};

const closeMachineDetail = () => {
    detailOpen.value = false;
    detailMachine.value = null;
    sessionStatus.value = null;
    loadingSessionStatus.value = false;
    syncingSalesMode.value = null;
};

const syncMachineSales = async (mode: SalesSyncMode = 'complete-documents') => {
    if (!detailMachine.value || syncingSalesMode.value !== null) {
        return;
    }

    syncingSalesMode.value = mode;

    try {
        const response = await axios.post(
            route('admin.events.tpas.sync-sales', [props.event.id, detailMachine.value.id]),
            { redirect_to: getCurrentPageUrl(), mode },
        );
        const message = response.data?.message as string | undefined;

        void showSuccessToast(message ?? 'Sincronização iniciada. O dashboard vai atualizar automaticamente.');
        router.reload({
            only: ['machines', 'sync_status'],
        });
    } catch (error: unknown) {
        const responseMessage = axios.isAxiosError(error)
            ? error.response?.data?.message as string | undefined
            : undefined;

        void showErrorToast(responseMessage ?? 'Não foi possível iniciar a sincronização das vendas.');
    } finally {
        syncingSalesMode.value = null;
    }
};

const stopSyncPolling = () => {
    if (syncPollerId.value === null) {
        return;
    }

    window.clearInterval(syncPollerId.value);
    syncPollerId.value = null;
};

const startSyncPolling = () => {
    if (syncPollerId.value !== null) {
        return;
    }

    syncPollerId.value = window.setInterval(() => {
        if (props.sync_status.status !== 'processing' || refreshingSyncStatus.value) {
            return;
        }

        refreshingSyncStatus.value = true;
        router.reload({
            only: ['machines', 'sync_status'],
            onFinish: () => {
                refreshingSyncStatus.value = false;
            },
        });
    }, 5000);
};

watch(() => props.sync_status.status, (status) => {
    if (status === 'processing') {
        startSyncPolling();
    } else {
        stopSyncPolling();
    }
});

watch(() => props.machines, (machines) => {
    if (!detailMachine.value) {
        return;
    }

    detailMachine.value = machines.find((machine) => machine.id === detailMachine.value?.id) ?? null;
});

const handlePointerDown = (event: MouseEvent) => {
    if (
        pickerContainer.value
        && event.target instanceof Node
        && !pickerContainer.value.contains(event.target)
    ) {
        pickerOpen.value = false;
    }
};

onMounted(() => {
    document.addEventListener('mousedown', handlePointerDown);

    if (props.sync_status.status === 'processing') {
        startSyncPolling();
    }
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', handlePointerDown);
    stopSyncPolling();
});

const saveSelection = () => {
    form.machine_ids = selectedMachineIds.value;
    form.put(route('admin.events.tpas.sync', props.event.id), {
        preserveScroll: true,
        onSuccess: () => void showSuccessToast('TPAs do evento atualizados com sucesso.'),
        onError: () => void showErrorToast('Não foi possível atualizar os TPAs do evento.'),
    });
};

const validateEventMachineLabels = async () => {
    if (validatingMachines.value) {
        return;
    }

    validatingMachines.value = true;

    try {
        const response = await axios.post(
            route('admin.events.integrations.machines.validate-all', props.event.id),
        );
        const message = String(response.data?.message ?? 'Validação concluída.');

        if (Number(response.data?.failed ?? 0) > 0) {
            void showErrorToast(message);
        } else {
            void showSuccessToast(message);
        }

        router.reload({ only: ['machines'] });
    } catch (error: unknown) {
        const message = axios.isAxiosError(error)
            ? (error.response?.data?.message as string | undefined) ?? 'Não foi possível atualizar os nomes dos TPAs.'
            : 'Não foi possível atualizar os nomes dos TPAs.';
        void showErrorToast(message);
    } finally {
        validatingMachines.value = false;
    }
};
</script>

<template>
    <Head :title="`Gerir TPA - ${props.event.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex w-full flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="dash-page-title">Gerir TPA</h2>
                    <p class="dash-muted-text">
                        {{ props.event.title }} · {{ props.client.name }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Link :href="route('admin.integrations.zonesoft.index')" class="dash-link-button">
                        Catálogo global
                    </Link>
                    <Link :href="route('admin.events.dashboard', props.event.id)" class="dash-link-button">
                        Voltar ao dashboard
                    </Link>
                </div>
            </div>
        </template>

        <div class="dash-page space-y-6">
            <section v-if="props.event.requires_explicit_zones && pendingZoneCount" class="rounded-2xl border border-amber-400/30 bg-amber-500/10 px-6 py-5 text-sm">
                <p class="font-semibold text-amber-200">{{ pendingZoneCount }} TPA{{ pendingZoneCount === 1 ? '' : 's' }} ativo{{ pendingZoneCount === 1 ? '' : 's' }} sem zona</p>
                <p class="mt-1 text-current/70">A sincronização fica suspensa até atribuir cada TPA a uma zona do evento.</p>
                <Link :href="route('admin.events.zones.manage', props.event.id)" class="mt-2 inline-block font-semibold text-sky-300 underline">Atribuir zonas</Link>
            </section>
            <section class="rounded-2xl border border-current/10 bg-white/[0.02] px-6 py-5">
                <div class="grid gap-4 lg:grid-cols-[minmax(18rem,42rem)_auto] lg:items-center lg:justify-between">
                    <div class="max-w-2xl">
                        <label class="dash-modal-label" for="event_zonesoft_license">
                            Licença ZoneSoft deste evento
                        </label>
                        <select
                            id="event_zonesoft_license"
                            v-model="selectedLicense"
                            class="dash-modal-input"
                            :disabled="!licenses.length"
                            @change="changeLicense"
                        >
                            <option value="" disabled>Selecione uma licença</option>
                            <option v-for="license in licenses" :key="license" :value="license">
                                {{ license }}
                            </option>
                        </select>
                        <p class="mt-2 text-sm text-current/55">
                            Cada edição usa uma licença e apenas os TPAs selecionados abaixo.
                        </p>
                        <p v-if="form.errors.machine_ids" class="dash-modal-error">
                            {{ form.errors.machine_ids }}
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-3 lg:justify-end">
                        <button
                            type="button"
                            class="dash-link-button"
                            :disabled="validatingMachines || form.processing || !props.machines.some((machine) => machine.is_selected)"
                            @click="validateEventMachineLabels"
                        >
                            {{ validatingMachines ? 'A atualizar nomes...' : 'Atualizar nomes dos TPAs do evento' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-xl border border-sky-400/20 bg-sky-500/10 px-5 py-3 text-sm font-semibold text-sky-100 transition hover:bg-sky-500/20 disabled:cursor-not-allowed"
                            :disabled="form.processing || validatingMachines"
                            :class="{ 'opacity-60': form.processing || validatingMachines }"
                            @click="saveSelection"
                        >
                            {{ form.processing ? 'A guardar...' : 'Guardar TPAs do evento' }}
                        </button>
                    </div>
                </div>
            </section>

            <section v-if="!props.machines.length" class="event-dashboard-empty">
                Este cliente ainda não possui TPAs no catálogo global.
                <Link :href="route('admin.integrations.zonesoft.index')" class="font-semibold underline">
                    Cadastrar integrações
                </Link>
            </section>

            <template v-else>
                <section class="dash-card">
                    <div class="flex flex-col gap-5">
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-current">
                                    {{ licenseMachines.length }} TPA{{ licenseMachines.length === 1 ? '' : 's' }} disponível{{ licenseMachines.length === 1 ? '' : 'is' }}
                                </p>
                                <p class="dash-recent-subtitle mt-1">
                                    {{ selectedCount }} selecionado{{ selectedCount === 1 ? '' : 's' }} para este evento
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-3 xl:justify-end">
                                <button
                                    type="button"
                                    class="dash-link-button"
                                    :disabled="!filteredMachines.length"
                                    @click="toggleAllMachines"
                                >
                                    {{ allSelected ? 'Limpar resultados' : 'Selecionar resultados' }}
                                </button>
                                <div class="inline-flex overflow-hidden rounded-xl border border-current/15">
                                    <button
                                        type="button"
                                        class="px-3 py-2 text-sm font-semibold transition"
                                        :class="viewMode === 'cards' ? 'bg-sky-500 text-white' : 'hover:bg-current/5'"
                                        @click="viewMode = 'cards'"
                                    >
                                        Cartões
                                    </button>
                                    <button
                                        type="button"
                                        class="border-l border-current/15 px-3 py-2 text-sm font-semibold transition"
                                        :class="viewMode === 'list' ? 'bg-sky-500 text-white' : 'hover:bg-current/5'"
                                        @click="viewMode = 'list'"
                                    >
                                        Lista
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="inline-flex w-full max-w-max rounded-2xl border border-current/10 bg-white/[0.02] p-1">
                            <button
                                type="button"
                                class="rounded-xl px-4 py-2 text-sm font-semibold transition"
                                :class="activeTab === 'catalog' ? 'bg-sky-500 text-white shadow-sm' : 'text-current/70 hover:bg-white/[0.04]'"
                                @click="activeTab = 'catalog'"
                            >
                                Catálogo
                                <span class="ml-2 text-xs opacity-80">{{ filteredMachines.length }}</span>
                            </button>
                            <button
                                type="button"
                                class="rounded-xl px-4 py-2 text-sm font-semibold transition"
                                :class="activeTab === 'linked' ? 'bg-sky-500 text-white shadow-sm' : 'text-current/70 hover:bg-white/[0.04]'"
                                @click="activeTab = 'linked'"
                            >
                                TPAs do evento
                                <span class="ml-2 text-xs opacity-80">{{ selectedMachineSummary.length }}</span>
                            </button>
                        </div>

                        <div v-if="activeTab === 'catalog'" class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,1fr)]">
                            <div>
                                <label class="sr-only" for="tpa_search">Pesquisar TPA</label>
                                <input
                                    id="tpa_search"
                                    v-model="search"
                                    type="search"
                                    class="dash-modal-input"
                                    placeholder="Pesquisar nome, Store ID ou Client ID..."
                                />
                            </div>

                            <div ref="pickerContainer" class="relative">
                                <button
                                    type="button"
                                    class="dash-modal-input flex w-full items-center justify-between gap-3 text-left"
                                    :aria-expanded="pickerOpen"
                                    aria-haspopup="listbox"
                                    @click="togglePicker"
                                >
                                    <span class="truncate text-sm text-current/80">
                                        {{ pickerSearch.trim() !== '' ? `Filtrar dropdown: ${pickerSearch.trim()}` : 'Selecionar TPAs' }}
                                    </span>
                                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-current/45">
                                        {{ pickerMachines.length }}
                                    </span>
                                </button>

                                <div
                                    v-if="pickerOpen"
                                    class="absolute right-0 z-20 mt-2 w-full rounded-2xl border border-current/10 bg-slate-950 p-3 shadow-2xl shadow-slate-950/35"
                                >
                                    <label class="sr-only" for="tpa_picker_search">Pesquisar no dropdown</label>
                                    <input
                                        id="tpa_picker_search"
                                        v-model="pickerSearch"
                                        type="search"
                                        class="dash-modal-input"
                                        placeholder="Filtrar TPA no dropdown..."
                                    />

                                    <div
                                        v-if="pickerMachines.length"
                                        class="mt-3 max-h-80 space-y-2 overflow-y-auto pr-1"
                                        role="listbox"
                                        aria-label="Selecionar TPAs"
                                    >
                                        <button
                                            v-for="machine in pickerMachines"
                                            :key="machine.id"
                                            type="button"
                                            class="flex w-full items-start justify-between gap-3 rounded-xl border px-4 py-3 text-left transition"
                                            :class="selectedMachineIds.includes(machine.id)
                                                ? 'border-sky-400/70 bg-sky-400/10'
                                                : 'border-current/10 bg-white/[0.03] hover:border-sky-400/40'"
                                            @click="toggleMachineFromPicker(machine.id)"
                                        >
                                            <div class="min-w-0">
                                                <p class="truncate text-sm font-semibold text-current">
                                                    {{ machine.store_label || `TPA ${machine.store_id}` }}
                                                </p>
                                                <p class="mt-1 text-xs text-current/55">
                                                    Store {{ machine.store_id }} · {{ machine.zs_client_id }}
                                                </p>
                                            </div>
                                            <span
                                                class="shrink-0 rounded-full px-2 py-1 text-[11px] font-semibold"
                                                :class="selectedMachineIds.includes(machine.id)
                                                    ? 'bg-sky-500/20 text-sky-200'
                                                    : 'bg-current/10 text-current/65'"
                                            >
                                                {{ selectedMachineIds.includes(machine.id) ? 'Selecionado' : 'Selecionar' }}
                                            </span>
                                        </button>
                                    </div>

                                    <p v-else class="mt-3 text-sm text-current/60">
                                        Nenhum TPA encontrado no dropdown.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div v-else class="rounded-2xl border border-current/10 bg-white/[0.02] p-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <h3 class="text-base font-semibold text-current">TPAs vinculados ao evento</h3>
                                    <p class="dash-recent-subtitle mt-1">
                                        Clique num TPA para abrir o painel lateral com o estado da sessão e a sincronização das vendas.
                                    </p>
                                </div>
                                <span class="rounded-full bg-current/5 px-3 py-1 text-xs font-semibold uppercase tracking-[0.16em] text-current/60">
                                    {{ selectedMachineSummary.length }} vinculado{{ selectedMachineSummary.length === 1 ? '' : 's' }}
                                </span>
                            </div>

                            <div v-if="selectedMachineSummary.length" class="mt-4 flex flex-wrap gap-3">
                                <button
                                    v-for="machine in selectedMachineSummary"
                                    :key="`selected-${machine.id}`"
                                    type="button"
                                    class="rounded-2xl border border-current/10 bg-white/[0.03] px-4 py-3 text-left transition hover:border-sky-400/45 hover:bg-sky-500/5"
                                    @click="openMachineDetail(machine)"
                                >
                                    <p class="text-sm font-semibold text-current">{{ machine.title }}</p>
                                    <p class="mt-1 text-xs text-current/55">
                                        Store {{ machine.store_id }} · {{ machine.zs_client_id }}
                                    </p>
                                    <span
                                        v-if="machine.sync_diagnostics && machine.sync_diagnostics.excluded_rows_count > 0"
                                        class="mt-2 inline-flex rounded-full bg-amber-400/15 px-2 py-1 text-[11px] font-semibold text-amber-200"
                                    >
                                        {{ machine.sync_diagnostics.excluded_rows_count }} venda(s) fora do período
                                    </span>
                                </button>
                            </div>

                            <p v-else class="mt-4 text-sm text-current/60">
                                Ainda não existem TPAs vinculados a este evento.
                            </p>
                        </div>
                    </div>

                    <p v-if="activeTab === 'catalog'" class="dash-recent-subtitle mt-5 border-t border-current/10 pt-5">
                        {{ filteredMachines.length }} resultado{{ filteredMachines.length === 1 ? '' : 's' }} apresentado{{ filteredMachines.length === 1 ? '' : 's' }}
                        <template v-if="search.trim() !== ''"> para “{{ search.trim() }}”</template>.
                    </p>
                </section>

                <section v-if="activeTab === 'catalog' && !filteredMachines.length" class="event-dashboard-empty">
                    <template v-if="search.trim() !== ''">
                        Não foram encontrados TPAs para esta pesquisa.
                    </template>
                    <template v-else>
                        Não existem TPAs cadastrados para a licença selecionada.
                    </template>
                </section>

                <section v-else-if="activeTab === 'catalog' && viewMode === 'cards'" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <article
                        v-for="machine in filteredMachines"
                        :key="machine.id"
                        class="relative rounded-2xl border p-5 transition"
                        :class="selectedMachineIds.includes(machine.id)
                            ? 'border-sky-400 bg-sky-400/10 shadow-lg shadow-sky-950/10'
                            : 'border-current/10 bg-current/[0.025] hover:border-sky-400/50'"
                    >
                        <label class="absolute right-4 top-4 flex cursor-pointer items-center">
                            <input
                                :checked="selectedMachineIds.includes(machine.id)"
                                type="checkbox"
                                class="h-5 w-5 rounded border-current/30 text-sky-500 focus:ring-sky-500"
                                :disabled="!machine.is_active"
                                :aria-label="`Selecionar ${machine.store_label || `TPA ${machine.store_id}`}`"
                                @change="toggleMachine(machine.id)"
                            />
                        </label>

                        <div class="flex items-start gap-3 pr-8">
                            <span class="rounded-xl bg-sky-500/10 p-3 text-sky-500">
                                <AppSidebarIcon name="tpa" class="h-6 w-6" />
                            </span>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[0.16em] text-current/55">
                                    Store {{ machine.store_id }}
                                </p>
                                <h3 class="mt-1 text-lg font-semibold text-current">
                                    {{ machine.store_label || `TPA ${machine.store_id}` }}
                                </h3>
                            </div>
                        </div>

                        <dl class="mt-6 space-y-3 text-sm">
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-current/60">Client ID</dt>
                                <dd class="max-w-[12rem] truncate font-medium text-current">{{ machine.zs_client_id }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-current/60">Estado</dt>
                                <dd>
                                    <span class="status-pill" :class="machine.is_active ? 'success' : 'neutral'">
                                        {{ machine.is_active ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-current/60">Última validação</dt>
                                <dd class="mt-1 font-medium text-current">{{ formatDateTime(machine.last_validated_at) }}</dd>
                            </div>
                        </dl>

                        <button
                            v-if="selectedMachineIds.includes(machine.id)"
                            type="button"
                            class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-sky-200 transition hover:text-sky-100"
                            @click="openMachineDetail(machine)"
                        >
                            Ver painel do TPA
                        </button>
                        <p
                            v-if="machine.sync_diagnostics && machine.sync_diagnostics.excluded_rows_count > 0"
                            class="mt-3 text-sm font-semibold text-amber-500"
                        >
                            Atenção: {{ formatMoney(machine.sync_diagnostics.excluded_sales_total) }} fora do período
                        </p>
                    </article>
                </section>

                <section v-else-if="activeTab === 'catalog'" class="dash-card overflow-x-auto p-0">
                    <table class="admin-clients-table min-w-[820px]">
                        <thead>
                            <tr>
                                <th class="w-12"></th>
                                <th>TPA</th>
                                <th>Store ID</th>
                                <th>Client ID</th>
                                <th>Estado</th>
                                <th>Última validação</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="machine in filteredMachines" :key="machine.id">
                                <td>
                                    <input
                                        :checked="selectedMachineIds.includes(machine.id)"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-current/30 text-sky-500 focus:ring-sky-500"
                                        :disabled="!machine.is_active"
                                        @change="toggleMachine(machine.id)"
                                    />
                                </td>
                                <td class="admin-clients-text">
                                    {{ machine.store_label || `TPA ${machine.store_id}` }}
                                    <span
                                        v-if="machine.sync_diagnostics && machine.sync_diagnostics.excluded_rows_count > 0"
                                        class="mt-1 block text-xs font-semibold text-amber-500"
                                    >
                                        {{ formatMoney(machine.sync_diagnostics.excluded_sales_total) }} fora do período
                                    </span>
                                </td>
                                <td class="admin-clients-text">{{ machine.store_id }}</td>
                                <td class="admin-clients-text">{{ machine.zs_client_id }}</td>
                                <td class="admin-clients-text">
                                    <span class="status-pill" :class="machine.is_active ? 'success' : 'neutral'">
                                        {{ machine.is_active ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </td>
                                <td class="admin-clients-text">{{ formatDateTime(machine.last_validated_at) }}</td>
                                <td class="admin-clients-text">
                                    <button
                                        v-if="selectedMachineIds.includes(machine.id)"
                                        type="button"
                                        class="text-sm font-semibold text-sky-200 transition hover:text-sky-100"
                                        @click="openMachineDetail(machine)"
                                    >
                                        Abrir painel
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </template>
        </div>

        <div v-if="detailOpen && detailMachine" class="fixed inset-0 z-50 flex justify-end bg-slate-950/55 backdrop-blur-sm" @click.self="closeMachineDetail">
            <aside class="flex h-full w-full max-w-2xl flex-col overflow-hidden border-l border-white/10 bg-slate-950 shadow-2xl shadow-slate-950/40">
                <header class="flex items-start justify-between gap-4 border-b border-white/10 px-6 py-6">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-300/80">TPA do evento</p>
                        <h3 class="mt-2 text-2xl font-semibold text-white">
                            {{ detailMachine.store_label || `Store ${detailMachine.store_id}` }}
                        </h3>
                        <p class="mt-2 text-sm text-slate-300">
                            Store {{ detailMachine.store_id }} · {{ detailMachine.zs_client_id }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="flex h-11 w-11 items-center justify-center rounded-full bg-white/6 text-2xl text-slate-200 transition hover:bg-white/12"
                        aria-label="Fechar detalhe"
                        @click="closeMachineDetail"
                    >
                        ×
                    </button>
                </header>

                <div class="flex-1 space-y-6 overflow-y-auto px-6 py-6">
                    <section class="grid gap-4 md:grid-cols-2">
                        <article class="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Estado do TPA</p>
                            <div class="mt-4 flex items-center gap-3">
                                <span class="status-pill" :class="detailMachine.is_active ? 'success' : 'neutral'">
                                    {{ detailMachine.is_active ? 'Ativo' : 'Inativo' }}
                                </span>
                                <span class="text-sm text-slate-300">{{ detailMachine.permissions || 'Sem permissões registadas' }}</span>
                            </div>
                            <p class="mt-4 text-sm text-slate-400">
                                Última validação: {{ formatDateTime(detailMachine.last_validated_at) }}
                            </p>
                            <p v-if="detailMachine.last_error" class="mt-3 text-sm text-amber-300">
                                {{ detailMachine.last_error }}
                            </p>
                        </article>

                        <article class="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Sessão ZoneSoft</p>
                            <div v-if="loadingSessionStatus" class="mt-4 text-sm text-slate-300">
                                A consultar estado da sessão...
                            </div>
                            <template v-else-if="sessionStatus">
                                <div class="mt-4 flex items-center gap-3">
                                    <span
                                        class="rounded-full px-3 py-1 text-sm font-semibold"
                                        :class="sessionStatus.status === 'open'
                                            ? 'bg-emerald-500/15 text-emerald-200'
                                            : sessionStatus.status === 'closed'
                                                ? 'bg-slate-700 text-slate-200'
                                                : 'bg-amber-500/15 text-amber-200'"
                                    >
                                        {{ sessionStatus.label }}
                                    </span>
                                </div>
                                <p class="mt-4 text-sm text-slate-300">{{ sessionStatus.message }}</p>
                                <dl v-if="sessionStatus.session" class="mt-4 space-y-3 text-sm">
                                    <div class="flex items-center justify-between gap-4">
                                        <dt class="text-slate-400">Caixa</dt>
                                        <dd class="font-medium text-white">{{ sessionStatus.session.cash_register }}</dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-4">
                                        <dt class="text-slate-400">Aberta em</dt>
                                        <dd class="font-medium text-white">{{ formatShortDateTime(sessionStatus.session.opened_at) }}</dd>
                                    </div>
                                    <div class="flex items-center justify-between gap-4">
                                        <dt class="text-slate-400">Aberta por</dt>
                                        <dd class="font-medium text-white">{{ sessionStatus.session.opened_by || 'Sem operador' }}</dd>
                                    </div>
                                    <div v-if="sessionStatus.session.session_id !== null" class="flex items-center justify-between gap-4">
                                        <dt class="text-slate-400">ID sessão</dt>
                                        <dd class="font-medium text-white">{{ sessionStatus.session.session_id }}</dd>
                                    </div>
                                </dl>
                            </template>
                        </article>
                    </section>

                    <section
                        v-if="detailMachine.sync_diagnostics"
                        class="rounded-2xl border p-5"
                        :class="detailMachine.sync_diagnostics.excluded_rows_count > 0
                            || detailMachine.sync_diagnostics.published_matches_in_range === false
                            ? 'border-amber-400/30 bg-amber-500/[0.08]'
                            : 'border-emerald-400/25 bg-emerald-500/[0.06]'"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">
                                    Reconciliação administrativa
                                </p>
                                <h4 class="mt-2 text-lg font-semibold text-white">
                                    {{ detailMachine.sync_diagnostics.excluded_rows_count > 0
                                        ? 'Existem vendas fora do período'
                                        : detailMachine.sync_diagnostics.published_matches_in_range === false
                                            ? 'O total publicado precisa de revisão'
                                            : detailMachine.sync_diagnostics.sync_mode === 'full'
                                                ? 'Sincronização conciliada'
                                                : 'Sem vendas excluídas nesta execução' }}
                                </h4>
                                <p class="mt-2 text-sm text-slate-300">
                                    Período: {{ formatShortDateTime(detailMachine.sync_diagnostics.range_start) }}
                                    — {{ formatShortDateTime(detailMachine.sync_diagnostics.range_end) }}
                                </p>
                            </div>
                            <span
                                class="self-start rounded-full px-3 py-1 text-xs font-semibold"
                                :class="detailMachine.sync_diagnostics.excluded_rows_count > 0
                                    || detailMachine.sync_diagnostics.published_matches_in_range === false
                                    ? 'bg-amber-400/15 text-amber-200'
                                    : 'bg-emerald-400/15 text-emerald-200'"
                            >
                                {{ detailMachine.sync_diagnostics.sync_mode === 'full' ? 'Verificação completa' : 'Verificação incremental' }}
                            </span>
                        </div>

                        <dl class="mt-5 grid gap-3 text-sm sm:grid-cols-3">
                            <div class="rounded-xl bg-black/15 p-3">
                                <dt class="text-slate-400">
                                    {{ detailMachine.sync_diagnostics.sync_mode === 'full'
                                        ? 'Recebido da ZoneSoft'
                                        : 'Alterações recebidas' }}
                                </dt>
                                <dd class="mt-1 font-semibold text-white">
                                    {{ formatMoney(detailMachine.sync_diagnostics.source_sales_total) }}
                                </dd>
                                <dd class="mt-1 text-xs text-slate-400">
                                    {{ detailMachine.sync_diagnostics.source_rows_count }} linha(s)
                                </dd>
                            </div>
                            <div class="rounded-xl bg-black/15 p-3">
                                <dt class="text-slate-400">Dentro do período</dt>
                                <dd class="mt-1 font-semibold text-white">
                                    {{ formatMoney(detailMachine.sync_diagnostics.in_range_sales_total) }}
                                </dd>
                                <dd class="mt-1 text-xs text-slate-400">
                                    {{ detailMachine.sync_diagnostics.in_range_rows_count }} linha(s)
                                </dd>
                            </div>
                            <div class="rounded-xl bg-black/15 p-3">
                                <dt class="text-slate-400">Publicado no relatório</dt>
                                <dd class="mt-1 font-semibold text-white">
                                    {{ formatMoney(detailMachine.sync_diagnostics.published_sales_total) }}
                                </dd>
                                <dd class="mt-1 text-xs text-slate-400">
                                    {{ detailMachine.sync_diagnostics.published_rows_count }} linha(s)
                                </dd>
                            </div>
                        </dl>

                        <div
                            v-if="detailMachine.sync_diagnostics.excluded_rows_count > 0"
                            class="mt-5 rounded-xl border border-amber-300/20 bg-amber-950/20 p-4"
                        >
                            <p class="font-semibold text-amber-100">
                                {{ detailMachine.sync_diagnostics.excluded_rows_count }} linha(s),
                                {{ formatMoney(detailMachine.sync_diagnostics.excluded_sales_total) }},
                                não entraram no relatório por causa do horário configurado.
                            </p>
                            <div class="mt-4 space-y-3">
                                <article
                                    v-for="document in detailMachine.sync_diagnostics.excluded_documents"
                                    :key="`${document.doc_type}-${document.document_series}-${document.document_number}`"
                                    class="rounded-lg bg-black/15 p-3 text-sm"
                                >
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <strong class="text-white">{{ documentLabel(document) }}</strong>
                                        <span class="font-semibold text-amber-100">{{ formatMoney(document.sales_total) }}</span>
                                    </div>
                                    <p class="mt-1 text-slate-300">
                                        {{ formatShortDateTime(document.sale_datetime) }} ·
                                        {{ document.reasons.map(exclusionReason).join(', ') }}
                                    </p>
                                    <p v-if="document.products.length" class="mt-1 text-xs text-slate-400">
                                        {{ document.products.join(', ') }}
                                    </p>
                                </article>
                            </div>
                            <p
                                v-if="detailMachine.sync_diagnostics.excluded_documents_omitted > 0"
                                class="mt-3 text-xs text-amber-100/75"
                            >
                                Mais {{ detailMachine.sync_diagnostics.excluded_documents_omitted }} documento(s) no registo da sincronização.
                            </p>
                        </div>

                        <p class="mt-4 text-xs text-slate-400">
                            Última verificação: {{ formatShortDateTime(detailMachine.sync_diagnostics.synced_at) }}.
                            Estes dados são visíveis apenas na administração.
                        </p>
                    </section>

                    <section class="rounded-2xl border border-white/10 bg-white/[0.03] p-5">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Vendas</p>
                                <h4 class="mt-2 text-lg font-semibold text-white">Sincronização deste TPA</h4>
                                <p class="mt-2 text-sm text-slate-300">
                                    Atualiza apenas as vendas deste TPA, sem voltar a consultar os restantes dispositivos do evento.
                                </p>
                                <p v-if="props.sync_status.status === 'processing'" class="mt-2 text-sm font-medium text-sky-200">
                                    Sincronização em curso. A reconciliação será atualizada automaticamente quando terminar.
                                </p>
                            </div>

                            <div class="flex flex-col gap-2 sm:flex-row">
                                <button
                                    type="button"
                                    class="rounded-xl border border-sky-400/20 bg-sky-500/10 px-5 py-3 text-sm font-semibold text-sky-100 transition hover:bg-sky-500/20 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="syncingSalesMode !== null || (props.event.requires_explicit_zones && pendingZoneCount > 0)"
                                    @click="syncMachineSales('complete-documents')"
                                >
                                    {{ syncingSalesMode === 'complete-documents' ? 'A sincronizar...' : 'Sincronizar normal' }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-xl border border-amber-400/25 bg-amber-500/10 px-5 py-3 text-sm font-semibold text-amber-100 transition hover:bg-amber-500/20 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="syncingSalesMode !== null || (props.event.requires_explicit_zones && pendingZoneCount > 0)"
                                    title="Consulta cada documento diretamente na interface de vendas da ZoneSoft."
                                    @click="syncMachineSales('document-sales')"
                                >
                                    {{ syncingSalesMode === 'document-sales' ? 'A sincronizar...' : 'Sincronização alternativa' }}
                                </button>
                            </div>
                        </div>
                        <p class="mt-3 text-xs text-amber-100/75">
                            A alternativa consulta as linhas de cada documento pela interface de vendas e deve ser usada quando a sincronização normal não trouxer algum produto.
                        </p>
                    </section>
                </div>
            </aside>
        </div>
    </AuthenticatedLayout>
</template>
