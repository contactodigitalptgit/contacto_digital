<script setup lang="ts">
import AppSidebarIcon from '@/Components/AppSidebarIcon.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface EventData {
    id: number;
    title: string;
    event_date: string;
}

interface MachineItem {
    id: number;
    store_id: number;
    store_label: string | null;
    license: string | null;
    is_active: boolean;
    last_validated_at: string | null;
    last_error: string | null;
}

const props = defineProps<{
    event: EventData;
    machines: MachineItem[];
}>();

const viewMode = ref<'cards' | 'list'>('cards');
const selectedMachineIds = ref<number[]>([]);

const allSelected = computed(
    () => props.machines.length > 0 && selectedMachineIds.value.length === props.machines.length,
);
const selectedCount = computed(() => selectedMachineIds.value.length);

const formatDateTime = (date: string | null) => date
    ? new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(date))
    : 'Ainda não validado';

const toggleMachine = (machineId: number) => {
    selectedMachineIds.value = selectedMachineIds.value.includes(machineId)
        ? selectedMachineIds.value.filter((id) => id !== machineId)
        : [...selectedMachineIds.value, machineId];
};

const toggleAllMachines = () => {
    selectedMachineIds.value = allSelected.value
        ? []
        : props.machines.map((machine) => machine.id);
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
                        {{ props.event.title }} · TPAs vinculados a este evento
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Link
                        :href="route('admin.events.dashboard', props.event.id)"
                        class="dash-link-button"
                    >
                        Voltar ao dashboard
                    </Link>
                    <Link
                        :href="route('admin.events.integrations.show', props.event.id)"
                        class="dash-action-button dash-action-button-inline"
                    >
                        Configurar integrações
                    </Link>
                </div>
            </div>
        </template>

        <div class="dash-page space-y-6">
            <section class="dash-card">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-current">
                            {{ props.machines.length }} TPA{{ props.machines.length === 1 ? '' : 's' }} vinculado{{ props.machines.length === 1 ? '' : 's' }}
                        </p>
                        <p class="dash-recent-subtitle mt-1">
                            Selecione os TPAs que quer usar no próximo passo.
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="dash-link-button"
                            :disabled="!props.machines.length"
                            :class="{ 'opacity-60': !props.machines.length }"
                            @click="toggleAllMachines"
                        >
                            {{ allSelected ? 'Limpar seleção' : 'Selecionar todos' }}
                        </button>
                        <span class="rounded-full bg-slate-500/10 px-3 py-2 text-sm font-semibold text-current">
                            {{ selectedCount }} selecionado{{ selectedCount === 1 ? '' : 's' }}
                        </span>
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
            </section>

            <section v-if="!props.machines.length" class="event-dashboard-empty">
                Ainda não existem TPAs vinculados a este evento. Pode associá-los em Configurar integrações.
            </section>

            <section v-else-if="viewMode === 'cards'" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="machine in props.machines"
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
                            :aria-label="`Selecionar ${machine.store_label || `TPA ${machine.store_id}`}`"
                            @change="toggleMachine(machine.id)"
                        />
                    </label>

                    <div class="flex items-start gap-3 pr-8">
                        <span class="rounded-xl bg-sky-500/10 p-3 text-sky-500">
                            <AppSidebarIcon name="tpa" class="h-6 w-6" />
                        </span>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-[0.16em] text-current/55">TPA · Store {{ machine.store_id }}</p>
                            <h3 class="mt-1 text-lg font-semibold text-current">
                                {{ machine.store_label || `TPA ${machine.store_id}` }}
                            </h3>
                        </div>
                    </div>

                    <dl class="mt-6 space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-current/60">Estado</dt>
                            <dd>
                                <span class="status-pill" :class="machine.is_active ? 'success' : 'neutral'">
                                    {{ machine.is_active ? 'Ativo' : 'Inativo' }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-current/60">Licença</dt>
                            <dd class="truncate text-right font-medium text-current">{{ machine.license || 'Sem licença' }}</dd>
                        </div>
                        <div>
                            <dt class="text-current/60">Última validação</dt>
                            <dd class="mt-1 font-medium text-current">{{ formatDateTime(machine.last_validated_at) }}</dd>
                        </div>
                    </dl>

                    <p v-if="machine.last_error" class="mt-4 rounded-xl bg-rose-500/10 px-3 py-2 text-xs leading-relaxed text-rose-600 dark:text-rose-200">
                        {{ machine.last_error }}
                    </p>
                </article>
            </section>

            <section v-else class="dash-card overflow-x-auto p-0">
                <table class="admin-clients-table min-w-[780px]">
                    <thead>
                        <tr>
                            <th class="w-12">
                                <input
                                    :checked="allSelected"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-current/30 text-sky-500 focus:ring-sky-500"
                                    aria-label="Selecionar todos os TPAs"
                                    @change="toggleAllMachines"
                                />
                            </th>
                            <th>TPA</th>
                            <th>Store ID</th>
                            <th>Licença</th>
                            <th>Estado</th>
                            <th>Última validação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="machine in props.machines"
                            :key="machine.id"
                            :class="{ 'bg-sky-500/5': selectedMachineIds.includes(machine.id) }"
                        >
                            <td>
                                <input
                                    :checked="selectedMachineIds.includes(machine.id)"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-current/30 text-sky-500 focus:ring-sky-500"
                                    :aria-label="`Selecionar ${machine.store_label || `TPA ${machine.store_id}`}`"
                                    @change="toggleMachine(machine.id)"
                                />
                            </td>
                            <td class="admin-clients-text">
                                <p class="font-semibold text-current">{{ machine.store_label || `TPA ${machine.store_id}` }}</p>
                                <p v-if="machine.last_error" class="mt-1 text-xs text-rose-600 dark:text-rose-200">{{ machine.last_error }}</p>
                            </td>
                            <td class="admin-clients-text">{{ machine.store_id }}</td>
                            <td class="admin-clients-text">{{ machine.license || 'Sem licença' }}</td>
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
        </div>
    </AuthenticatedLayout>
</template>
