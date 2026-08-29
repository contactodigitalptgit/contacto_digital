<script setup lang="ts">
import AppSidebarIcon from '@/Components/AppSidebarIcon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { showErrorToast, showSuccessToast } from '@/lib/swal';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

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
const activeTab = ref<'catalog' | 'selected'>('catalog');
const viewMode = ref<'cards' | 'list'>('cards');
const search = ref('');
const form = useForm({ machine_ids: selectedMachineIds.value });

const licenseMachines = computed(() => props.machines.filter(
    (machine) => (machine.license?.trim() ?? '') === selectedLicense.value,
));
const selectedCount = computed(() => selectedMachineIds.value.length);
const tabMachines = computed(() => activeTab.value === 'selected'
    ? licenseMachines.value.filter((machine) => selectedMachineIds.value.includes(machine.id))
    : licenseMachines.value,
);
const filteredMachines = computed(() => {
    const normalizedSearch = search.value.trim().toLocaleLowerCase('pt-PT');

    if (normalizedSearch === '') {
        return tabMachines.value;
    }

    return tabMachines.value.filter((machine) => [
        machine.store_label,
        machine.store_id.toString(),
        machine.zs_client_id,
    ].some((value) => value?.toLocaleLowerCase('pt-PT').includes(normalizedSearch)));
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
    activeTab.value = 'catalog';
    search.value = '';
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
            <section class="dash-card space-y-5">
                <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                    <div class="max-w-xl">
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
                        <p class="admin-event-input-hint">
                            Cada edição usa uma licença e apenas os TPAs selecionados abaixo.
                        </p>
                        <p v-if="form.errors.machine_ids" class="dash-modal-error">
                            {{ form.errors.machine_ids }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="dash-action-button dash-action-button-inline"
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
                    <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                        <div>
                            <p class="text-sm font-semibold text-current">
                                {{ licenseMachines.length }} TPA{{ licenseMachines.length === 1 ? '' : 's' }} disponível{{ licenseMachines.length === 1 ? '' : 'is' }}
                            </p>
                            <p class="dash-recent-subtitle mt-1">
                                {{ selectedCount }} selecionado{{ selectedCount === 1 ? '' : 's' }} para este evento
                            </p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-[minmax(18rem,1fr)_auto_auto] xl:w-[42rem]">
                            <label class="sr-only" for="tpa_search">Pesquisar TPA</label>
                            <input
                                id="tpa_search"
                                v-model="search"
                                type="search"
                                class="dash-modal-input"
                                placeholder="Pesquisar nome, Store ID ou Client ID..."
                            />
                            <label class="sr-only" for="tpa_view_mode">Visualização</label>
                            <select id="tpa_view_mode" v-model="viewMode" class="dash-modal-input min-w-36">
                                <option value="cards">Cartões</option>
                                <option value="list">Lista</option>
                            </select>
                            <button
                                type="button"
                                class="dash-link-button"
                                :disabled="!filteredMachines.length"
                                @click="toggleAllMachines"
                            >
                                {{ allSelected ? 'Limpar resultados' : 'Selecionar resultados' }}
                            </button>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2 border-t border-current/10 pt-5" role="tablist" aria-label="TPAs do evento">
                        <button
                            type="button"
                            class="rounded-xl px-4 py-2 text-sm font-semibold transition"
                            :class="activeTab === 'catalog' ? 'bg-sky-500 text-white shadow-sm' : 'bg-current/[0.04] text-current/70 hover:bg-current/[0.08]'"
                            :aria-selected="activeTab === 'catalog'"
                            role="tab"
                            @click="activeTab = 'catalog'"
                        >
                            Catálogo
                            <span class="ml-1 opacity-75">{{ licenseMachines.length }}</span>
                        </button>
                        <button
                            type="button"
                            class="rounded-xl px-4 py-2 text-sm font-semibold transition"
                            :class="activeTab === 'selected' ? 'bg-sky-500 text-white shadow-sm' : 'bg-current/[0.04] text-current/70 hover:bg-current/[0.08]'"
                            :aria-selected="activeTab === 'selected'"
                            role="tab"
                            @click="activeTab = 'selected'"
                        >
                            TPAs do evento
                            <span class="ml-1 opacity-75">{{ selectedCount }}</span>
                        </button>
                    </div>

                    <p class="dash-recent-subtitle mt-4">
                        {{ filteredMachines.length }} resultado{{ filteredMachines.length === 1 ? '' : 's' }} apresentado{{ filteredMachines.length === 1 ? '' : 's' }}
                        <template v-if="search.trim() !== ''"> para “{{ search.trim() }}”</template>.
                    </p>
                </section>

                <section v-if="!filteredMachines.length" class="event-dashboard-empty">
                    <template v-if="activeTab === 'selected' && search.trim() === ''">
                        Ainda não existem TPAs selecionados para este evento.
                    </template>
                    <template v-else-if="search.trim() !== ''">
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
