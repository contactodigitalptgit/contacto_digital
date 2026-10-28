<script setup lang="ts">
import Modal from '@/Components/Modal.vue';
import EventSidebarNavigation from '@/Components/EventSidebarNavigation.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmAction, showErrorToast, showSuccessToast } from '@/lib/swal';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

interface MachineItem {
    id: number;
    store_id: number;
    store_label: string | null;
    zs_client_id: string;
    is_active: boolean;
    operator_name: string | null;
    zonesoft_name: string | null;
    zonesoft_name_synced_at: string | null;
    zonesoft_name_error: string | null;
}

interface ImportPreviewRow {
    line: number | null;
    store_id: number | null;
    machine_id: number | null;
    current_label: string | null;
    zone: string;
    zone_exists: boolean;
    operator: string | null;
    starts_at: string | null;
    zonesoft_name: string;
    errors: string[];
}

interface ImportPreview {
    rows: ImportPreviewRow[];
    summary: { total: number; valid: number; invalid: number; new_zones: number };
}

interface ZoneItem {
    id: number;
    name: string;
    sort_order: number;
    total_sales: number;
    machines: MachineItem[];
}

interface HistoryItem {
    id: number;
    zone: string;
    operator_name: string | null;
    machine: string;
    starts_at: string;
    ends_at: string | null;
    source: string;
    assigned_by: string | null;
    sales_total: number;
}

interface DayItem {
    id: number;
    operational_date: string;
    starts_at: string;
    ends_at: string;
    confirmed_at: string | null;
}

const props = defineProps<{
    event: {
        id: number;
        title: string;
        event_date: string | null;
        report_starts_at: string | null;
        report_ends_at: string | null;
        requires_explicit_zones: boolean;
        zonesoft_names_enabled: boolean;
        legacy_zone_ends_at: string | null;
    };
    client: { id: number; name: string };
    default_effective_at: string;
    days: DayItem[];
    selected_day_id: number | null;
    has_legacy_zones: boolean;
    zones: ZoneItem[];
    unassigned_machines: MachineItem[];
    history: HistoryItem[];
}>();

const createForm = useForm({ name: '', day_id: props.selected_day_id });
const dayForm = useForm({ operational_date: '', starts_at: '', ends_at: '' });
const editDayForm = useForm({ operational_date: '', starts_at: '', ends_at: '' });
const closeLegacyForm = useForm({ ends_at: props.event.legacy_zone_ends_at ?? '' });
const editingDay = ref<DayItem | null>(null);
const editForm = useForm({ name: '' });
const moveForm = useForm({ effective_at: props.default_effective_at, operator_name: '' });
const ZONESOFT_NAME_LIMIT = 50;
const bulkForm = useForm({ machine_ids: [] as number[], effective_at: props.default_effective_at });
const editingZone = ref<ZoneItem | null>(null);
const draggedMachine = ref<MachineItem | null>(null);
const draggedFromZoneId = ref<number | null>(null);
const targetZone = ref<ZoneItem | null>(null);
const bulkZoneId = ref<number | null>(null);
const selectedPendingIds = ref<number[]>([]);

const totalMachines = computed(() => props.zones.reduce(
    (total, zone) => total + zone.machines.length,
    props.unassigned_machines.length,
));
const selectedDay = computed(() => props.days.find((day) => day.id === props.selected_day_id) ?? null);
const canCreateZone = computed(() => props.selected_day_id !== null || props.days.length === 0);

const selectDay = (value: string) => {
    router.get(route('admin.events.zones.manage', props.event.id), { day: value }, { preserveState: false });
};

const createDay = () => {
    dayForm.post(route('admin.events.zones.days.store', props.event.id), {
        onSuccess: () => {
            dayForm.reset();
            void showSuccessToast('Dia operacional criado.');
        },
    });
};

const openEditDay = (day: DayItem) => {
    editingDay.value = day;
    editDayForm.operational_date = day.operational_date;
    editDayForm.starts_at = day.starts_at;
    editDayForm.ends_at = day.ends_at;
};

const updateDay = () => {
    if (!editingDay.value) return;
    editDayForm.patch(route('admin.events.zones.days.update', [props.event.id, editingDay.value.id]), {
        onSuccess: () => {
            editingDay.value = null;
            void showSuccessToast('Dia atualizado.');
        },
        onError: (errors) => void showErrorToast((errors.day as string | undefined) ?? 'Não foi possível atualizar o dia.'),
    });
};

const deleteDay = async (day: DayItem) => {
    const confirmed = await confirmAction({
        title: 'Eliminar dia vazio?',
        text: 'Só um dia não confirmado e sem zonas pode ser eliminado.',
        confirmButtonText: 'Eliminar dia',
    });
    if (!confirmed) return;
    router.delete(route('admin.events.zones.days.destroy', [props.event.id, day.id]), {
        onError: (errors) => void showErrorToast((errors.day as string | undefined) ?? 'Não foi possível eliminar o dia.'),
    });
};

const confirmDay = async (day: DayItem) => {
    const confirmed = await confirmAction({
        title: `Confirmar ${day.operational_date}?`,
        text: 'Confirme apenas depois de distribuir todos os TPAs ativos pelas zonas deste dia.',
        confirmButtonText: 'Confirmar plano',
    });
    if (!confirmed) return;
    router.post(route('admin.events.zones.days.confirm', [props.event.id, day.id]), {}, {
        onSuccess: () => void showSuccessToast('Plano do dia confirmado.'),
        onError: (errors) => void showErrorToast((errors.day as string | undefined) ?? 'Não foi possível confirmar o dia.'),
    });
};

const closeLegacy = async () => {
    if (!closeLegacyForm.ends_at) return;
    const confirmed = await confirmAction({
        title: 'Fechar atribuições antigas?',
        text: `As atribuições das zonas antigas vão terminar em ${closeLegacyForm.ends_at} e as zonas serão arquivadas. As vendas anteriores mantêm-se.`,
        confirmButtonText: 'Confirmar fecho',
    });
    if (!confirmed) return;
    closeLegacyForm.post(route('admin.events.zones.legacy.close', props.event.id), {
        onSuccess: () => void showSuccessToast('Atribuições antigas encerradas.'),
        onError: (errors) => void showErrorToast((errors.ends_at as string | undefined) ?? 'Não foi possível encerrar as atribuições.'),
    });
};

const formatMoney = (value: number) => new Intl.NumberFormat('pt-PT', {
    style: 'currency',
    currency: 'EUR',
}).format(value);

const formatDateTime = (value: string | null) => value
    ? new Intl.DateTimeFormat('pt-PT', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : 'Em vigor';

const machineName = (machine: MachineItem) => machine.store_label?.trim() || `Store ${machine.store_id}`;

const zonesoftNameFor = (zoneName: string, operatorName: string) => (
    operatorName.trim() ? `${zoneName.trim()} - ${operatorName.trim()}` : zoneName.trim()
);

type ZoneSoftNameState = 'synced' | 'pending' | 'error';

const zonesoftNameState = (machine: MachineItem): ZoneSoftNameState => {
    if (machine.zonesoft_name_error) return 'error';

    return machine.zonesoft_name_synced_at && machine.store_label?.trim() === machine.zonesoft_name
        ? 'synced'
        : 'pending';
};

const pendingZoneSoftNames = computed(() => props.zones
    .flatMap((zone) => zone.machines)
    .filter((machine) => zonesoftNameState(machine) === 'pending').length);

// Enquanto houver nomes a caminho da ZoneSoft, a página atualiza sozinha
// durante uns segundos para mostrar o resultado.
let refreshTimer: ReturnType<typeof setInterval> | null = null;
let refreshTicks = 0;

const stopRefresh = () => {
    if (refreshTimer) clearInterval(refreshTimer);
    refreshTimer = null;
};

const startRefresh = () => {
    stopRefresh();
    refreshTicks = 0;
    refreshTimer = setInterval(() => {
        refreshTicks += 1;
        if (refreshTicks > 12 || pendingZoneSoftNames.value === 0) {
            stopRefresh();

            return;
        }
        router.reload({ only: ['zones', 'unassigned_machines'] });
    }, 5000);
};

watch(pendingZoneSoftNames, (count) => {
    if (count === 0) stopRefresh();
});

onBeforeUnmount(stopRefresh);

const pushNamesForm = useForm({ day_id: props.selected_day_id, machine_id: null as number | null });

const pushMachineName = (machine: MachineItem) => {
    pushNamesForm.day_id = props.selected_day_id;
    pushNamesForm.machine_id = machine.id;
    pushNamesForm.post(route('admin.events.zones.zonesoft-names.push', props.event.id), {
        preserveScroll: true,
        onSuccess: () => {
            void showSuccessToast(`A enviar "${machine.zonesoft_name}".`);
            startRefresh();
        },
    });
};
const toggleNamesForm = useForm({ enabled: props.event.zonesoft_names_enabled });

const toggleZoneSoftNames = async () => {
    const enabling = !props.event.zonesoft_names_enabled;
    const confirmed = await confirmAction({
        title: enabling ? 'Ligar nomes automáticos?' : 'Desligar nomes automáticos?',
        text: enabling
            ? 'Durante o evento (e nas 24 h antes de cada dia confirmado), cada mudança de zona ou de operador muda o nome do TPA na ZoneSoft. TPAs sem operador ficam só com o nome da zona.'
            : 'As mudanças feitas no portal deixam de alterar os nomes na ZoneSoft. Pode continuar a enviar manualmente.',
        confirmButtonText: enabling ? 'Ligar' : 'Desligar',
    });
    if (!confirmed) return;

    toggleNamesForm.enabled = enabling;
    toggleNamesForm.patch(route('admin.events.zones.zonesoft-names.toggle', props.event.id), {
        preserveScroll: true,
        onSuccess: () => {
            if (enabling) startRefresh();
        },
    });
};

const pushZoneSoftNames = async () => {
    const confirmed = await confirmAction({
        title: 'Enviar nomes para a ZoneSoft?',
        text: 'Cada TPA deste período passa a chamar-se "Zona - Operador" no backoffice da ZoneSoft.',
        confirmButtonText: 'Enviar nomes',
    });
    if (!confirmed) return;

    pushNamesForm.day_id = props.selected_day_id;
    pushNamesForm.machine_id = null;
    pushNamesForm.post(route('admin.events.zones.zonesoft-names.push', props.event.id), {
        preserveScroll: true,
        onSuccess: () => {
            void showSuccessToast('Envio iniciado.');
            startRefresh();
        },
    });
};

const importOpen = ref(false);
const importFile = ref<File | null>(null);
const importPreview = ref<ImportPreview | null>(null);
const importLoading = ref(false);
const importSaving = ref(false);
const importError = ref('');

const openImport = () => {
    importOpen.value = true;
    importFile.value = null;
    importPreview.value = null;
    importError.value = '';
};

const onImportFile = (event: Event) => {
    importFile.value = (event.target as HTMLInputElement).files?.[0] ?? null;
    importPreview.value = null;
    importError.value = '';
};

const previewImport = async () => {
    if (!importFile.value) return;

    const data = new FormData();
    data.append('file', importFile.value);
    if (props.selected_day_id !== null) data.append('day_id', String(props.selected_day_id));

    importLoading.value = true;
    importError.value = '';
    try {
        const response = await axios.post<ImportPreview>(route('admin.events.zones.import.preview', props.event.id), data);
        importPreview.value = response.data;
    } catch (error) {
        const errors = axios.isAxiosError(error) ? error.response?.data?.errors : null;
        importError.value = (errors && (Object.values(errors)[0] as string[] | undefined)?.[0])
            || 'Não foi possível ler o ficheiro.';
    } finally {
        importLoading.value = false;
    }
};

const applyImport = () => {
    if (!importPreview.value?.summary.valid) return;

    importSaving.value = true;
    router.post(route('admin.events.zones.import.store', props.event.id), {
        day_id: props.selected_day_id,
        rows: importPreview.value.rows
            .filter((row) => !row.errors.length)
            .map((row) => ({
                store_id: row.store_id,
                zone: row.zone,
                operator: row.operator,
                starts_at: row.starts_at,
            })),
    }, {
        preserveScroll: true,
        onSuccess: () => {
            importOpen.value = false;
            void showSuccessToast('TPAs importados.');
            startRefresh();
        },
        onError: (errors) => {
            importError.value = (Object.values(errors)[0] as string | undefined) ?? 'Não foi possível importar.';
        },
        onFinish: () => {
            importSaving.value = false;
        },
    });
};

const moveSameZone = computed(() => targetZone.value !== null && draggedFromZoneId.value === targetZone.value.id);
const movePreviewName = computed(() => zonesoftNameFor(targetZone.value?.name ?? '', moveForm.operator_name));

const openOperator = (machine: MachineItem, zone: ZoneItem) => {
    draggedMachine.value = machine;
    draggedFromZoneId.value = zone.id;
    targetZone.value = zone;
    moveForm.effective_at = props.default_effective_at;
    moveForm.operator_name = machine.operator_name ?? '';
    moveForm.clearErrors();
};

const createZone = () => {
    createForm.day_id = props.selected_day_id;
    createForm.post(route('admin.events.zones.store', props.event.id), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            void showSuccessToast('Zona criada.');
        },
    });
};

const openEditZone = (zone: ZoneItem) => {
    editingZone.value = zone;
    editForm.name = zone.name;
    editForm.clearErrors();
};

const closeEditZone = () => {
    editingZone.value = null;
    editForm.reset();
};

const updateZone = () => {
    if (!editingZone.value) return;

    editForm.patch(route('admin.events.zones.update', [props.event.id, editingZone.value.id]), {
        preserveScroll: true,
        onSuccess: () => {
            closeEditZone();
            void showSuccessToast('Zona atualizada.');
        },
    });
};

const archiveZone = async (zone: ZoneItem) => {
    const confirmed = await confirmAction({
        title: `Arquivar ${zone.name}?`,
        text: zone.machines.length
            ? 'Esta zona ainda tem TPAs. Mova-os antes de arquivar.'
            : 'O histórico e os valores anteriores serão preservados.',
        confirmButtonText: 'Arquivar zona',
    });

    if (!confirmed) return;

    router.delete(route('admin.events.zones.destroy', [props.event.id, zone.id]), {
        preserveScroll: true,
        onSuccess: () => void showSuccessToast('Zona arquivada.'),
        onError: (errors) => void showErrorToast(
            (errors.zone as string | undefined) ?? 'Não foi possível arquivar esta zona.',
        ),
    });
};

const startDrag = (machine: MachineItem, fromZoneId: number | null) => {
    draggedMachine.value = machine;
    draggedFromZoneId.value = fromZoneId;
};

const dropOnZone = (zone: ZoneItem) => {
    if (!draggedMachine.value || draggedFromZoneId.value === zone.id) return;

    targetZone.value = zone;
    moveForm.effective_at = props.default_effective_at;
    moveForm.operator_name = draggedMachine.value.operator_name ?? '';
    moveForm.clearErrors();
};

const closeMove = () => {
    targetZone.value = null;
    draggedMachine.value = null;
    draggedFromZoneId.value = null;
    moveForm.clearErrors();
};

const confirmMove = () => {
    if (!targetZone.value || !draggedMachine.value) return;

    moveForm.post(route('admin.events.zones.machines.move', [
        props.event.id,
        targetZone.value.id,
        draggedMachine.value.id,
    ]), {
        preserveScroll: true,
        onSuccess: () => {
            selectedPendingIds.value = selectedPendingIds.value.filter((id) => id !== draggedMachine.value?.id);
            const sameZone = moveSameZone.value;
            closeMove();
            void showSuccessToast(sameZone ? 'Operador atualizado.' : 'TPA movido e faturação recalculada.');
            startRefresh();
        },
    });
};

const togglePending = (id: number) => {
    selectedPendingIds.value = selectedPendingIds.value.includes(id)
        ? selectedPendingIds.value.filter((selectedId) => selectedId !== id)
        : [...selectedPendingIds.value, id];
};

const toggleAllPending = () => {
    const allIds = props.unassigned_machines.map((machine) => machine.id);
    selectedPendingIds.value = allIds.every((id) => selectedPendingIds.value.includes(id))
        ? []
        : allIds;
};

const assignSelected = () => {
    if (!bulkZoneId.value || !selectedPendingIds.value.length) return;

    bulkForm.machine_ids = selectedPendingIds.value;
    bulkForm.post(route('admin.events.zones.machines.assign', [props.event.id, bulkZoneId.value]), {
        preserveScroll: true,
        onSuccess: () => {
            selectedPendingIds.value = [];
            bulkZoneId.value = null;
            bulkForm.reset();
            void showSuccessToast('TPAs atribuídos à zona.');
            startRefresh();
        },
        onError: (errors) => void showErrorToast(
            (errors.machine_ids as string | undefined) ?? 'Não foi possível atribuir os TPAs.',
        ),
    });
};
</script>

<template>
    <Head :title="`Gerir zonas - ${props.event.title}`" />

    <AuthenticatedLayout>
        <template #sidebar-navigation="{ isAdmin }">
            <EventSidebarNavigation :event-id="props.event.id" :is-admin="isAdmin" active="zones-manage" />
        </template>

        <template #header>
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-500">{{ props.client.name }}</p>
                    <h2 class="mt-1 text-2xl font-bold text-current">Gerir zonas</h2>
                    <p class="mt-1 text-sm text-current/60">{{ props.event.title }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link :href="route('admin.events.tpas.manage', props.event.id)" class="dash-link-button">
                        Gerir TPA
                    </Link>
                    <Link :href="route('admin.events.dashboard', props.event.id)" class="dash-link-button">
                        Voltar ao dashboard
                    </Link>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-[1600px] space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <section class="rounded-3xl border border-current/10 bg-white/[0.03] p-5 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold">Organização operacional do evento</h3>
                        <p class="mt-1 max-w-3xl text-sm text-current/60">
                            Crie as zonas reais e atribua todos os TPAs antes de sincronizar vendas. Arraste um TPA para mudar de zona e indique quando a mudança entrou em vigor.
                            Cada TPA passa a chamar-se "Zona - Operador" na ZoneSoft (ex.: Bar 1 - Ana Caldas).
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full bg-current/5 px-3 py-2 text-xs font-semibold uppercase tracking-[0.12em] text-current/65">{{ props.zones.length }} zonas</span>
                        <span class="rounded-full bg-current/5 px-3 py-2 text-xs font-semibold uppercase tracking-[0.12em] text-current/65">{{ totalMachines }} TPAs</span>
                        <button v-if="canCreateZone" type="button" class="dash-link-button" @click="openImport">Importar Excel</button>
                        <button
                            type="button"
                            class="dash-link-button"
                            :class="props.event.zonesoft_names_enabled ? '!border-emerald-400/50 !text-emerald-300' : ''"
                            :disabled="toggleNamesForm.processing"
                            @click="toggleZoneSoftNames"
                        >
                            Nomes automáticos: {{ props.event.zonesoft_names_enabled ? 'ligado' : 'desligado' }}
                        </button>
                        <button
                            v-if="props.zones.length"
                            type="button"
                            class="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            :disabled="pushNamesForm.processing"
                            @click="pushZoneSoftNames"
                        >
                            Enviar nomes para a ZoneSoft
                        </button>
                    </div>
                </div>
            </section>

            <section class="space-y-4 rounded-3xl border border-current/10 bg-white/[0.03] p-5">
                <div>
                    <h3 class="text-lg font-semibold">Dias operacionais</h3>
                    <p class="mt-1 text-sm text-current/60">Cada dia tem horários e zonas próprios. Um período pode terminar depois da meia-noite. O histórico anterior não é copiado automaticamente.</p>
                </div>
                <div v-if="props.days.length || props.has_legacy_zones" class="flex flex-wrap items-end gap-3">
                    <label class="min-w-64 text-sm font-semibold">Ver período
                        <select class="dash-modal-input mt-2 w-full" :value="props.selected_day_id ?? 'legacy'" @change="selectDay(($event.target as HTMLSelectElement).value)">
                            <option v-if="props.has_legacy_zones" value="legacy">Zonas anteriores (histórico)</option>
                            <option v-for="day in props.days" :key="day.id" :value="day.id">{{ day.operational_date }} · {{ day.confirmed_at ? 'confirmado' : 'rascunho' }}</option>
                        </select>
                    </label>
                    <div v-if="selectedDay" class="text-sm text-current/70">
                        <p>{{ formatDateTime(selectedDay.starts_at) }} → {{ formatDateTime(selectedDay.ends_at) }}</p>
                        <p class="font-semibold" :class="selectedDay.confirmed_at ? 'text-emerald-400' : 'text-amber-300'">{{ selectedDay.confirmed_at ? 'Plano confirmado' : 'Pendente de confirmação' }}</p>
                    </div>
                    <div v-if="selectedDay" class="flex gap-2">
                        <button v-if="!selectedDay.confirmed_at" type="button" class="dash-link-button" @click="openEditDay(selectedDay)">Editar horários</button>
                        <button v-if="!selectedDay.confirmed_at" type="button" class="dash-link-button" @click="deleteDay(selectedDay)">Eliminar vazio</button>
                        <button v-if="!selectedDay.confirmed_at" type="button" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white" @click="confirmDay(selectedDay)">Confirmar plano</button>
                    </div>
                </div>
                <form class="grid gap-3 rounded-2xl border border-current/10 p-4 sm:grid-cols-4" @submit.prevent="createDay">
                    <div><label for="day_date" class="text-xs font-semibold uppercase text-current/60">Data operacional</label><input id="day_date" v-model="dayForm.operational_date" type="date" class="dash-modal-input mt-2 w-full" required /><p v-if="dayForm.errors.operational_date" class="mt-1 text-sm text-rose-400">{{ dayForm.errors.operational_date }}</p></div>
                    <div><label for="day_start" class="text-xs font-semibold uppercase text-current/60">Início</label><input id="day_start" v-model="dayForm.starts_at" type="datetime-local" class="dash-modal-input mt-2 w-full" required /><p v-if="dayForm.errors.starts_at" class="mt-1 text-sm text-rose-400">{{ dayForm.errors.starts_at }}</p></div>
                    <div><label for="day_end" class="text-xs font-semibold uppercase text-current/60">Fim</label><input id="day_end" v-model="dayForm.ends_at" type="datetime-local" class="dash-modal-input mt-2 w-full" required /><p v-if="dayForm.errors.ends_at" class="mt-1 text-sm text-rose-400">{{ dayForm.errors.ends_at }}</p></div>
                    <button class="self-end rounded-xl bg-sky-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50" :disabled="dayForm.processing">Criar dia</button>
                </form>
                <p v-if="props.has_legacy_zones && props.selected_day_id === null && props.event.legacy_zone_ends_at" class="rounded-2xl border border-emerald-400/30 bg-emerald-500/5 p-4 text-sm text-emerald-300">
                    Atribuições anteriores encerradas em {{ formatDateTime(props.event.legacy_zone_ends_at) }}. O histórico foi preservado.
                </p>
                <form v-if="props.has_legacy_zones && props.selected_day_id === null && !props.event.legacy_zone_ends_at" class="flex flex-wrap items-end gap-3 rounded-2xl border border-amber-400/30 bg-amber-500/5 p-4" @submit.prevent="closeLegacy">
                    <div class="flex-1"><p class="font-semibold text-amber-300">Encerrar atribuições anteriores</p><p class="mt-1 text-sm text-current/65">Indique a hora real em que terminou o período anterior. Não use a hora atual se o evento já terminou.</p></div>
                    <label class="text-xs font-semibold uppercase text-current/60">Hora de fecho<input v-model="closeLegacyForm.ends_at" type="datetime-local" class="dash-modal-input mt-2 block" required /></label>
                    <button class="rounded-xl bg-amber-600 px-4 py-3 text-sm font-semibold text-white" :disabled="closeLegacyForm.processing">Fechar anteriores</button>
                </form>
            </section>

            <section class="space-y-4">
                <form v-if="canCreateZone" class="flex flex-col gap-3 rounded-2xl border border-current/10 bg-white/[0.02] p-4 sm:flex-row" @submit.prevent="createZone">
                    <div class="flex-1">
                        <label for="zone_name" class="text-xs font-semibold uppercase tracking-[0.14em] text-current/60">Nova zona</label>
                        <input id="zone_name" v-model="createForm.name" class="dash-modal-input mt-2 w-full" maxlength="120" placeholder="Ex.: Bar 3" />
                        <p v-if="createForm.errors.name" class="mt-1 text-sm text-rose-400">{{ createForm.errors.name }}</p>
                    </div>
                    <button class="self-end rounded-xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white hover:bg-sky-500 disabled:opacity-60" :disabled="createForm.processing || !createForm.name.trim()">
                        Criar zona
                    </button>
                </form>

                <p v-if="!props.zones.length" class="rounded-2xl border border-dashed border-sky-400/40 bg-sky-500/5 p-6 text-sm text-current/70">
                    Ainda não há zonas neste período. {{ canCreateZone ? 'Crie a primeira zona acima; os TPAs permanecem pendentes até serem atribuídos por si.' : 'Selecione ou crie um dia operacional para configurar as zonas.' }}
                    <span v-if="!props.event.requires_explicit_zones" class="mt-2 block font-medium text-amber-300">Ao criar a primeira zona neste evento antigo, as próximas sincronizações vão aguardar a atribuição de todos os TPAs ativos.</span>
                </p>

                <div v-if="props.zones.length" class="grid gap-4 xl:grid-cols-3">
                    <article
                        v-for="zone in props.zones"
                        :key="zone.id"
                        class="min-h-64 rounded-3xl border border-current/10 bg-white/[0.03] p-4 transition hover:border-sky-400/40"
                        @dragover.prevent
                        @drop.prevent="dropOnZone(zone)"
                    >
                        <header class="flex items-start justify-between gap-3 border-b border-current/10 pb-4">
                            <div>
                                <h3 class="text-lg font-bold">{{ zone.name }}</h3>
                                <p class="mt-1 text-sm text-current/60">{{ zone.machines.length }} TPAs · {{ formatMoney(zone.total_sales) }}</p>
                            </div>
                            <div class="flex gap-1">
                                <button type="button" class="rounded-lg px-2 py-1 text-xs font-semibold text-sky-400 hover:bg-sky-500/10" @click="openEditZone(zone)">Editar</button>
                                <button type="button" class="rounded-lg px-2 py-1 text-xs font-semibold text-rose-400 hover:bg-rose-500/10" @click="archiveZone(zone)">Arquivar</button>
                            </div>
                        </header>

                        <div class="mt-4 space-y-2">
                            <div
                                v-for="machine in zone.machines"
                                :key="machine.id"
                                draggable="true"
                                class="cursor-grab rounded-2xl border border-current/10 bg-current/[0.03] p-3 active:cursor-grabbing"
                                @dragstart="startDrag(machine, zone.id)"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <p class="font-semibold">{{ machine.store_id }} · {{ machine.zonesoft_name || machineName(machine) }}</p>
                                    <button type="button" class="shrink-0 rounded-lg px-2 py-0.5 text-xs font-semibold text-sky-400 hover:bg-sky-500/10" @click="openOperator(machine, zone)">
                                        {{ machine.operator_name ? 'Operador' : '+ Operador' }}
                                    </button>
                                </div>
                                <p
                                    class="mt-1 text-xs"
                                    :class="{
                                        'text-emerald-400': zonesoftNameState(machine) === 'synced',
                                        'text-amber-300': zonesoftNameState(machine) === 'pending',
                                        'text-rose-400': zonesoftNameState(machine) === 'error',
                                    }"
                                    :title="machine.zonesoft_name_error ?? undefined"
                                >
                                    <template v-if="zonesoftNameState(machine) === 'synced'">✓ Nome igual na ZoneSoft</template>
                                    <template v-else-if="zonesoftNameState(machine) === 'error'">✕ {{ machine.zonesoft_name_error }}</template>
                                    <template v-else>Na ZoneSoft: {{ machineName(machine) }} · por enviar</template>
                                    <button
                                        v-if="zonesoftNameState(machine) !== 'synced'"
                                        type="button"
                                        class="ml-1 font-semibold text-sky-400 underline disabled:opacity-50"
                                        :disabled="pushNamesForm.processing"
                                        @click="pushMachineName(machine)"
                                    >
                                        Enviar
                                    </button>
                                </p>
                            </div>
                            <p v-if="!zone.machines.length" class="rounded-2xl border border-dashed border-current/15 p-6 text-center text-sm text-current/45">
                                Arraste um TPA para esta zona
                            </p>
                        </div>
                    </article>
                </div>

                <article v-if="props.unassigned_machines.length" class="rounded-3xl border border-amber-400/30 bg-amber-500/5 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 class="font-bold text-amber-300">TPAs sem zona ({{ props.unassigned_machines.length }})</h3>
                            <p class="mt-1 text-sm text-current/65">Atribua-os antes de sincronizar. Os nomes dos TPAs não criam zonas automaticamente.</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" class="dash-link-button" @click="toggleAllPending">Selecionar todos</button>
                            <button type="button" class="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="!selectedPendingIds.length || !props.zones.length" @click="bulkZoneId = props.zones[0].id">
                                Atribuir selecionados ({{ selectedPendingIds.length }})
                            </button>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <label
                            v-for="machine in props.unassigned_machines"
                            :key="machine.id"
                            draggable="true"
                            class="flex cursor-grab items-start gap-3 rounded-xl border border-amber-400/25 bg-amber-500/10 px-4 py-3"
                            @dragstart="startDrag(machine, null)"
                        >
                            <input type="checkbox" class="mt-1" :checked="selectedPendingIds.includes(machine.id)" @change="togglePending(machine.id)" />
                            <span><span class="block font-semibold">{{ machineName(machine) }}</span><span class="text-xs text-current/55">Store {{ machine.store_id }}</span></span>
                        </label>
                    </div>
                </article>
            </section>

            <section v-if="props.history.length" class="rounded-3xl border border-current/10 bg-white/[0.03] p-5">
                <h3 class="text-lg font-semibold">Histórico de atribuições</h3>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-[0.12em] text-current/50">
                            <tr><th class="px-3 py-2">TPA</th><th class="px-3 py-2">Zona</th><th class="px-3 py-2">Início</th><th class="px-3 py-2">Fim</th><th class="px-3 py-2">Faturação</th><th class="px-3 py-2">Alterado por</th></tr>
                        </thead>
                        <tbody class="divide-y divide-current/10">
                            <tr v-for="item in props.history" :key="item.id">
                                <td class="px-3 py-3 font-medium">{{ item.machine }}</td>
                                <td class="px-3 py-3">{{ item.zone }}<span v-if="item.operator_name" class="text-current/55"> · {{ item.operator_name }}</span></td>
                                <td class="px-3 py-3 text-current/65">{{ formatDateTime(item.starts_at) }}</td>
                                <td class="px-3 py-3 text-current/65">{{ formatDateTime(item.ends_at) }}</td>
                                <td class="px-3 py-3 font-semibold">{{ formatMoney(item.sales_total) }}</td>
                                <td class="px-3 py-3 text-current/65">{{ item.assigned_by || (item.source === 'initial' ? 'Geração inicial' : 'Sistema') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <Modal :show="editingDay !== null" max-width="md" @close="editingDay = null">
            <form class="space-y-4 p-6" @submit.prevent="updateDay">
                <div><h3 class="text-lg font-semibold">Editar dia operacional</h3><p class="mt-1 text-sm text-current/60">Só é possível alterar os horários antes da confirmação e das atribuições.</p></div>
                <div><label class="text-sm">Data operacional</label><input v-model="editDayForm.operational_date" type="date" class="dash-modal-input mt-2 w-full" required /></div>
                <div><label class="text-sm">Início</label><input v-model="editDayForm.starts_at" type="datetime-local" class="dash-modal-input mt-2 w-full" required /></div>
                <div><label class="text-sm">Fim</label><input v-model="editDayForm.ends_at" type="datetime-local" class="dash-modal-input mt-2 w-full" required /></div>
                <p v-if="editDayForm.errors.starts_at" class="text-sm text-rose-400">{{ editDayForm.errors.starts_at }}</p>
                <div class="flex justify-end gap-2"><button type="button" class="dash-link-button" @click="editingDay = null">Cancelar</button><button class="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white" :disabled="editDayForm.processing">Guardar</button></div>
            </form>
        </Modal>

        <Modal :show="editingZone !== null" max-width="md" @close="closeEditZone">
            <form class="space-y-4 p-6" @submit.prevent="updateZone">
                <div><h3 class="text-lg font-semibold">Renomear zona</h3><p class="mt-1 text-sm text-current/60">O identificador e todo o histórico serão preservados.</p></div>
                <input v-model="editForm.name" class="dash-modal-input w-full" maxlength="120" />
                <p v-if="editForm.errors.name" class="text-sm text-rose-400">{{ editForm.errors.name }}</p>
                <div class="flex justify-end gap-2"><button type="button" class="dash-link-button" @click="closeEditZone">Cancelar</button><button class="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white" :disabled="editForm.processing">Guardar</button></div>
            </form>
        </Modal>

        <Modal :show="targetZone !== null && draggedMachine !== null" max-width="md" @close="closeMove">
            <form class="space-y-5 p-6" @submit.prevent="confirmMove">
                <div><h3 class="text-lg font-semibold">{{ moveSameZone ? 'Operador do TPA' : `Mover TPA para ${targetZone?.name}` }}</h3><p class="mt-1 text-sm text-current/60">Store {{ draggedMachine?.store_id }} · {{ draggedMachine ? machineName(draggedMachine) : '' }}</p></div>
                <div><label for="operator_name" class="text-xs font-semibold uppercase tracking-[0.14em] text-current/60">Operador</label><input id="operator_name" v-model="moveForm.operator_name" class="dash-modal-input mt-2 w-full" maxlength="50" placeholder="Ex.: Ana Caldas" /><p v-if="moveForm.errors.operator_name" class="mt-1 text-sm text-rose-400">{{ moveForm.errors.operator_name }}</p></div>
                <p class="rounded-xl bg-current/5 p-3 text-sm">
                    Nome na ZoneSoft: <strong>{{ draggedMachine?.store_id }} - {{ movePreviewName }}</strong>
                    <span class="ml-1 text-xs" :class="movePreviewName.length > ZONESOFT_NAME_LIMIT ? 'text-rose-400' : 'text-current/50'">({{ movePreviewName.length }}/{{ ZONESOFT_NAME_LIMIT }})</span>
                </p>
                <div><label for="effective_at" class="text-xs font-semibold uppercase tracking-[0.14em] text-current/60">{{ moveSameZone ? 'Troca de operador em' : 'Mudança efetiva em' }}</label><input id="effective_at" v-model="moveForm.effective_at" type="datetime-local" class="dash-modal-input mt-2 w-full" /><p v-if="moveForm.errors.effective_at" class="mt-1 text-sm text-rose-400">{{ moveForm.errors.effective_at }}</p></div>
                <p v-if="!moveSameZone" class="rounded-xl bg-sky-500/10 p-3 text-sm text-current/70">As vendas anteriores a este horário ficam na zona de origem; as posteriores passam para {{ targetZone?.name }}.</p>
                <div class="flex justify-end gap-2"><button type="button" class="dash-link-button" @click="closeMove">Cancelar</button><button class="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="moveForm.processing || movePreviewName.length > ZONESOFT_NAME_LIMIT">{{ moveSameZone ? 'Guardar operador' : 'Confirmar mudança' }}</button></div>
            </form>
        </Modal>

        <Modal :show="bulkZoneId !== null" max-width="md" @close="bulkZoneId = null">
            <form class="space-y-5 p-6" @submit.prevent="assignSelected">
                <div><h3 class="text-lg font-semibold">Atribuir {{ selectedPendingIds.length }} TPAs</h3><p class="mt-1 text-sm text-current/60">Escolha a zona real e a hora a partir da qual as vendas pertencem a ela.</p></div>
                <div><label for="bulk_zone" class="text-xs font-semibold uppercase tracking-[0.14em] text-current/60">Zona</label><select id="bulk_zone" v-model.number="bulkZoneId" class="dash-modal-input mt-2 w-full"><option v-for="zone in props.zones" :key="zone.id" :value="zone.id">{{ zone.name }}</option></select></div>
                <div><label for="bulk_effective_at" class="text-xs font-semibold uppercase tracking-[0.14em] text-current/60">Atribuição efetiva em</label><input id="bulk_effective_at" v-model="bulkForm.effective_at" type="datetime-local" class="dash-modal-input mt-2 w-full" /><p v-if="bulkForm.errors.effective_at" class="mt-1 text-sm text-rose-400">{{ bulkForm.errors.effective_at }}</p></div>
                <div class="flex justify-end gap-2"><button type="button" class="dash-link-button" @click="bulkZoneId = null">Cancelar</button><button class="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="bulkForm.processing">Confirmar atribuição</button></div>
            </form>
        </Modal>
        <Modal :show="importOpen" max-width="2xl" @close="importOpen = false">
            <div class="space-y-5 p-6">
                <div>
                    <h3 class="text-lg font-semibold">Importar zonas por Excel</h3>
                    <p class="mt-1 text-sm text-current/60">
                        Colunas: <strong>Store ID</strong>, <strong>Zona</strong>, <strong>Operador</strong> e, se quiser, <strong>Início</strong> (ex.: 17:00).
                        Também aceita uma coluna com o nome completo, como "103 - Bar 1 - Ana Caldas".
                        Sem início, conta desde {{ selectedDay ? 'o início do dia operacional' : 'o início do evento' }}. As zonas que não existem são criadas.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <input type="file" accept=".xlsx,.xls,.csv" class="text-sm" @change="onImportFile" />
                    <button type="button" class="dash-link-button" :disabled="!importFile || importLoading" @click="previewImport">
                        {{ importLoading ? 'A ler...' : 'Pré-visualizar' }}
                    </button>
                </div>
                <p v-if="importError" class="rounded-xl bg-rose-500/10 p-3 text-sm text-rose-300">{{ importError }}</p>

                <template v-if="importPreview">
                    <div class="flex flex-wrap gap-2 text-xs font-semibold uppercase tracking-[0.12em]">
                        <span class="rounded-full bg-emerald-500/10 px-3 py-2 text-emerald-400">{{ importPreview.summary.valid }} válidas</span>
                        <span v-if="importPreview.summary.invalid" class="rounded-full bg-rose-500/10 px-3 py-2 text-rose-400">{{ importPreview.summary.invalid }} com erro</span>
                        <span v-if="importPreview.summary.new_zones" class="rounded-full bg-sky-500/10 px-3 py-2 text-sky-400">{{ importPreview.summary.new_zones }} zonas novas</span>
                    </div>
                    <div class="max-h-80 overflow-auto rounded-xl border border-current/10">
                        <table class="min-w-full text-left text-sm">
                            <thead class="sticky top-0 bg-[var(--dash-surface,#0b1626)] text-xs uppercase tracking-[0.12em] text-current/50">
                                <tr><th class="px-3 py-2">Linha</th><th class="px-3 py-2">Nome na ZoneSoft</th><th class="px-3 py-2">Início</th><th class="px-3 py-2">Estado</th></tr>
                            </thead>
                            <tbody class="divide-y divide-current/10">
                                <tr v-for="row in importPreview.rows" :key="`${row.line}-${row.store_id}`">
                                    <td class="px-3 py-2 text-current/55">{{ row.line }}</td>
                                    <td class="px-3 py-2 font-medium">
                                        {{ row.store_id ?? '—' }} - {{ row.zonesoft_name }}
                                        <span v-if="!row.zone_exists && !row.errors.length" class="ml-1 text-xs text-sky-400">zona nova</span>
                                    </td>
                                    <td class="px-3 py-2 text-current/65">{{ row.starts_at ? formatDateTime(row.starts_at) : '—' }}</td>
                                    <td class="px-3 py-2" :class="row.errors.length ? 'text-rose-400' : 'text-emerald-400'">
                                        {{ row.errors.length ? row.errors.join(' ') : 'OK' }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>

                <div class="flex justify-end gap-2">
                    <button type="button" class="dash-link-button" @click="importOpen = false">Cancelar</button>
                    <button
                        type="button"
                        class="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        :disabled="!importPreview?.summary.valid || importSaving"
                        @click="applyImport"
                    >
                        {{ importSaving ? 'A importar...' : `Importar ${importPreview?.summary.valid ?? 0} TPAs` }}
                    </button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
