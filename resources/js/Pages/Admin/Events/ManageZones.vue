<script setup lang="ts">
import Modal from '@/Components/Modal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmAction, showErrorToast, showSuccessToast } from '@/lib/swal';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface MachineItem {
    id: number;
    store_id: number;
    store_label: string | null;
    zs_client_id: string;
    is_active: boolean;
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
    machine: string;
    starts_at: string;
    ends_at: string | null;
    source: string;
    assigned_by: string | null;
    sales_total: number;
}

const props = defineProps<{
    event: {
        id: number;
        title: string;
        event_date: string | null;
        report_starts_at: string | null;
        report_ends_at: string | null;
    };
    client: { id: number; name: string };
    initialized: boolean;
    default_effective_at: string;
    zones: ZoneItem[];
    unassigned_machines: MachineItem[];
    history: HistoryItem[];
}>();

const createForm = useForm({ name: '' });
const editForm = useForm({ name: '' });
const moveForm = useForm({ effective_at: props.default_effective_at });
const initializeForm = useForm({});
const editingZone = ref<ZoneItem | null>(null);
const draggedMachine = ref<MachineItem | null>(null);
const draggedFromZoneId = ref<number | null>(null);
const targetZone = ref<ZoneItem | null>(null);

const totalMachines = computed(() => props.zones.reduce(
    (total, zone) => total + zone.machines.length,
    props.unassigned_machines.length,
));

const formatMoney = (value: number) => new Intl.NumberFormat('pt-PT', {
    style: 'currency',
    currency: 'EUR',
}).format(value);

const formatDateTime = (value: string | null) => value
    ? new Intl.DateTimeFormat('pt-PT', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value))
    : 'Em vigor';

const machineName = (machine: MachineItem) => machine.store_label?.trim() || `Store ${machine.store_id}`;

const initializeZones = () => {
    initializeForm.post(route('admin.events.zones.initialize', props.event.id), {
        preserveScroll: true,
        onSuccess: () => void showSuccessToast('Zonas iniciais geradas.'),
        onError: () => void showErrorToast('Não foi possível gerar as zonas iniciais.'),
    });
};

const createZone = () => {
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
            closeMove();
            void showSuccessToast('TPA movido e faturação recalculada.');
        },
    });
};
</script>

<template>
    <Head :title="`Gerir zonas - ${props.event.title}`" />

    <AuthenticatedLayout>
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
                            Arraste um TPA para outra zona e indique quando a mudança entrou em vigor. As vendas anteriores permanecem na zona original.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs font-semibold uppercase tracking-[0.12em] text-current/65">
                        <span class="rounded-full bg-current/5 px-3 py-2">{{ props.zones.length }} zonas</span>
                        <span class="rounded-full bg-current/5 px-3 py-2">{{ totalMachines }} TPAs</span>
                    </div>
                </div>
            </section>

            <section v-if="!props.initialized" class="rounded-3xl border border-dashed border-sky-400/40 bg-sky-500/5 p-8 text-center">
                <h3 class="text-xl font-semibold">Gerar configuração inicial</h3>
                <p class="mx-auto mt-2 max-w-2xl text-sm text-current/65">
                    As zonas serão criadas a partir dos nomes atuais dos TPAs. Depois poderá renomear zonas e mover os equipamentos livremente.
                </p>
                <button class="mt-5 rounded-xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white hover:bg-sky-500 disabled:opacity-60" :disabled="initializeForm.processing" @click="initializeZones">
                    {{ initializeForm.processing ? 'A gerar…' : 'Gerar zonas iniciais' }}
                </button>
            </section>

            <section v-else class="space-y-4">
                <form class="flex flex-col gap-3 rounded-2xl border border-current/10 bg-white/[0.02] p-4 sm:flex-row" @submit.prevent="createZone">
                    <div class="flex-1">
                        <label for="zone_name" class="text-xs font-semibold uppercase tracking-[0.14em] text-current/60">Nova zona</label>
                        <input id="zone_name" v-model="createForm.name" class="dash-modal-input mt-2 w-full" maxlength="120" placeholder="Ex.: Bar 3" />
                        <p v-if="createForm.errors.name" class="mt-1 text-sm text-rose-400">{{ createForm.errors.name }}</p>
                    </div>
                    <button class="self-end rounded-xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white hover:bg-sky-500 disabled:opacity-60" :disabled="createForm.processing || !createForm.name.trim()">
                        Criar zona
                    </button>
                </form>

                <div class="grid gap-4 xl:grid-cols-3">
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
                                <p class="font-semibold">{{ machineName(machine) }}</p>
                                <p class="mt-1 text-xs text-current/55">Store {{ machine.store_id }} · {{ machine.zs_client_id }}</p>
                            </div>
                            <p v-if="!zone.machines.length" class="rounded-2xl border border-dashed border-current/15 p-6 text-center text-sm text-current/45">
                                Arraste um TPA para esta zona
                            </p>
                        </div>
                    </article>
                </div>

                <article v-if="props.unassigned_machines.length" class="rounded-3xl border border-amber-400/30 bg-amber-500/5 p-4">
                    <h3 class="font-bold text-amber-300">TPAs sem zona</h3>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <div
                            v-for="machine in props.unassigned_machines"
                            :key="machine.id"
                            draggable="true"
                            class="cursor-grab rounded-xl border border-amber-400/25 bg-amber-500/10 px-4 py-3"
                            @dragstart="startDrag(machine, null)"
                        >
                            <p class="font-semibold">{{ machineName(machine) }}</p>
                            <p class="text-xs text-current/55">Store {{ machine.store_id }}</p>
                        </div>
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
                                <td class="px-3 py-3">{{ item.zone }}</td>
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
                <div><h3 class="text-lg font-semibold">Mover TPA para {{ targetZone?.name }}</h3><p class="mt-1 text-sm text-current/60">{{ draggedMachine ? machineName(draggedMachine) : '' }}</p></div>
                <div><label for="effective_at" class="text-xs font-semibold uppercase tracking-[0.14em] text-current/60">Mudança efetiva em</label><input id="effective_at" v-model="moveForm.effective_at" type="datetime-local" class="dash-modal-input mt-2 w-full" /><p v-if="moveForm.errors.effective_at" class="mt-1 text-sm text-rose-400">{{ moveForm.errors.effective_at }}</p></div>
                <p class="rounded-xl bg-sky-500/10 p-3 text-sm text-current/70">As vendas anteriores a este horário ficam na zona de origem; as posteriores passam para {{ targetZone?.name }}.</p>
                <div class="flex justify-end gap-2"><button type="button" class="dash-link-button" @click="closeMove">Cancelar</button><button class="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white" :disabled="moveForm.processing">Confirmar mudança</button></div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
