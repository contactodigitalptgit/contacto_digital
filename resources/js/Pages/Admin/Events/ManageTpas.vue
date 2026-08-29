<script setup lang="ts">
import AppSidebarIcon from '@/Components/AppSidebarIcon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { showErrorToast, showSuccessToast } from '@/lib/swal';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface EventData {
    id: number;
    title: string;
    event_date: string;
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
    is_active: boolean;
    last_validated_at: string | null;
    last_error: string | null;
    is_selected: boolean;
}

const props = defineProps<{
    event: EventData;
    client: ClientData;
    machines: MachineItem[];
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
const selectedMachineIds = ref<number[]>(
    initialSelectedMachines.map((machine) => machine.id),
);
const viewMode = ref<'cards' | 'list'>('cards');
const search = ref('');
const pickerOpen = ref(false);
const pickerSearch = ref('');
const pickerContainer = ref<HTMLElement | null>(null);
const form = useForm({ machine_ids: selectedMachineIds.value });

const licenseMachines = computed(() => props.machines.filter(
    (machine) => (machine.license?.trim() ?? '') === selectedLicense.value,
));
const selectedCount = computed(() => selectedMachineIds.value.length);
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

const formatDateTime = (date: string | null) => date
    ? new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(date))
    : 'Ainda não validado';

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
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', handlePointerDown);
});

const saveSelection = () => {
    form.machine_ids = selectedMachineIds.value;
    form.put(route('admin.events.tpas.sync', props.event.id), {
        preserveScroll: true,
        onSuccess: () => void showSuccessToast('TPAs do evento atualizados com sucesso.'),
        onError: () => void showErrorToast('Não foi possível atualizar os TPAs do evento.'),
    });
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

                    <button
                        type="button"
                        class="rounded-xl border border-sky-400/20 bg-sky-500/10 px-5 py-3 text-sm font-semibold text-sky-100 transition hover:bg-sky-500/20 disabled:cursor-not-allowed"
                        :disabled="form.processing"
                        :class="{ 'opacity-60': form.processing }"
                        @click="saveSelection"
                    >
                        {{ form.processing ? 'A guardar...' : 'Guardar TPAs do evento' }}
                    </button>
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

                        <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,1fr)]">
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
                    </div>

                    <p class="dash-recent-subtitle mt-5 border-t border-current/10 pt-5">
                        {{ filteredMachines.length }} resultado{{ filteredMachines.length === 1 ? '' : 's' }} apresentado{{ filteredMachines.length === 1 ? '' : 's' }}
                        <template v-if="search.trim() !== ''"> para “{{ search.trim() }}”</template>.
                    </p>
                </section>

                <section v-if="!filteredMachines.length" class="event-dashboard-empty">
                    <template v-if="search.trim() !== ''">
                        Não foram encontrados TPAs para esta pesquisa.
                    </template>
                    <template v-else>
                        Não existem TPAs cadastrados para a licença selecionada.
                    </template>
                </section>

                <section v-else-if="viewMode === 'cards'" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
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
                    </article>
                </section>

                <section v-else class="dash-card overflow-x-auto p-0">
                    <table class="admin-clients-table min-w-[820px]">
                        <thead>
                            <tr>
                                <th class="w-12"></th>
                                <th>TPA</th>
                                <th>Store ID</th>
                                <th>Client ID</th>
                                <th>Estado</th>
                                <th>Última validação</th>
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
                                <td class="admin-clients-text">{{ machine.store_label || `TPA ${machine.store_id}` }}</td>
                                <td class="admin-clients-text">{{ machine.store_id }}</td>
                                <td class="admin-clients-text">{{ machine.zs_client_id }}</td>
                                <td class="admin-clients-text">
                                    <span class="status-pill" :class="machine.is_active ? 'success' : 'neutral'">
                                        {{ machine.is_active ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </td>
                                <td class="admin-clients-text">{{ formatDateTime(machine.last_validated_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
