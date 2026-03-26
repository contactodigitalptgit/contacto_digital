<script setup lang="ts">
import Modal from '@/Components/Modal.vue';
import { confirmAction, showErrorToast, showSuccessToast } from '@/lib/swal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface ClientOption {
    id: number;
    name: string;
    business_name: string | null;
}

interface EventReportSummary {
    active_imports_count: number;
    active_rows_count: number;
    total: number;
    last_imported_at: string | null;
    last_filename: string;
}

interface EventItem {
    id: number;
    title: string;
    description: string | null;
    event_date: string;
    event_date_input: string;
    client_name: string;
    client_id: number;
    is_active: boolean;
    report_summary: EventReportSummary | null;
}

const props = defineProps<{
    events: EventItem[];
    clients: ClientOption[];
}>();

const showCreateEventModal = ref(false);
const showEditEventModal = ref(false);
const showImportReportModal = ref(false);
const editingEventId = ref<number | null>(null);
const selectedReportEvent = ref<EventItem | null>(null);
const reportFileInput = ref<HTMLInputElement | null>(null);

const createEventForm = useForm({
    client_id: '' as number | '',
    title: '',
    description: '',
    event_date: '',
});

const editEventForm = useForm({
    client_id: '' as number | '',
    title: '',
    description: '',
    event_date: '',
});

const reportImportForm = useForm({
    import_strategy: 'replace' as 'sum' | 'replace',
    report_file: null as File | null,
});

const formatDate = (date: string) =>
    new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(date));

const formatMoney = (value: number) =>
    new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency: 'EUR',
    }).format(value);

const openCreateEventModal = () => {
    createEventForm.reset();
    createEventForm.clearErrors();
    showCreateEventModal.value = true;
};

const closeCreateEventModal = () => {
    showCreateEventModal.value = false;
    createEventForm.clearErrors();
};

const submitCreateEvent = () => {
    createEventForm.post(route('admin.events.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCreateEventModal.value = false;
            createEventForm.reset();
            void showSuccessToast('Evento criado com sucesso.');
        },
    });
};

const openEditEventModal = (event: EventItem) => {
    editingEventId.value = event.id;
    editEventForm.client_id = event.client_id;
    editEventForm.title = event.title;
    editEventForm.description = event.description ?? '';
    editEventForm.event_date = event.event_date_input;
    editEventForm.clearErrors();
    showEditEventModal.value = true;
};

const closeEditEventModal = () => {
    showEditEventModal.value = false;
    editingEventId.value = null;
    editEventForm.clearErrors();
};

const openImportReportModal = (event: EventItem) => {
    selectedReportEvent.value = event;
    reportImportForm.reset();
    reportImportForm.clearErrors();
    reportImportForm.import_strategy = event.report_summary ? 'replace' : 'sum';
    showImportReportModal.value = true;

    if (reportFileInput.value) {
        reportFileInput.value.value = '';
    }
};

const closeImportReportModal = () => {
    showImportReportModal.value = false;
    selectedReportEvent.value = null;
    reportImportForm.reset();
    reportImportForm.clearErrors();

    if (reportFileInput.value) {
        reportFileInput.value.value = '';
    }
};

const handleReportFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;

    reportImportForm.report_file = input.files?.[0] ?? null;
};

const submitEditEvent = () => {
    if (!editingEventId.value) {
        return;
    }

    editEventForm.put(route('admin.events.update', editingEventId.value), {
        preserveScroll: true,
        onSuccess: () => {
            showEditEventModal.value = false;
            editingEventId.value = null;
            editEventForm.reset();
            void showSuccessToast('Evento atualizado com sucesso.');
        },
    });
};

const submitReportImport = () => {
    if (!selectedReportEvent.value) {
        return;
    }

    reportImportForm.post(route('admin.events.reports.store', selectedReportEvent.value.id), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            closeImportReportModal();
            void showSuccessToast('Relatorio importado com sucesso.');
        },
        onError: () => {
            if (!reportImportForm.errors.report_file) {
                void showErrorToast('Nao foi possivel importar o relatorio.');
            }
        },
    });
};

const toggleEventStatus = async (event: EventItem) => {
    const nextStatus = !event.is_active;
    const confirmed = await confirmAction({
        title: nextStatus ? 'Ativar evento?' : 'Desativar evento?',
        text: nextStatus
            ? 'O evento voltará a aparecer normalmente no dashboard.'
            : 'O evento ficará oculto do dashboard e do cliente.',
        confirmButtonText: nextStatus ? 'Ativar' : 'Desativar',
    });

    if (!confirmed) {
        return;
    }

    router.patch(
        route('admin.events.toggle-status', event.id),
        {
            is_active: nextStatus,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                void showSuccessToast(
                    nextStatus
                        ? 'Evento ativado com sucesso.'
                        : 'Evento desativado com sucesso.',
                );
            },
            onError: () => {
                void showErrorToast('Não foi possível atualizar o status do evento.');
            },
        },
    );
};

const deleteEvent = async (event: EventItem) => {
    const confirmed = await confirmAction({
        title: 'Deletar evento?',
        text: `Esta ação remove o evento \"${event.title}\" e não pode ser desfeita.`,
        confirmButtonText: 'Deletar',
    });

    if (!confirmed) {
        return;
    }

    router.delete(route('admin.events.destroy', event.id), {
        preserveScroll: true,
        onSuccess: () => {
            void showSuccessToast('Evento deletado com sucesso.');
        },
        onError: () => {
            void showErrorToast('Não foi possível deletar o evento.');
        },
    });
};
</script>

<template>
    <Head title="Eventos" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="dash-page-title">
                    Eventos
                </h2>
                <button
                    type="button"
                    @click="openCreateEventModal"
                    class="dash-action-button dash-action-button-inline w-full justify-center sm:w-auto"
                >
                    Novo evento
                </button>
            </div>
        </template>

        <div class="dash-page">
            <section class="dash-card">
                <div class="admin-events-mobile-list md:hidden">
                    <article
                        v-for="event in events"
                        :key="event.id"
                        class="admin-events-mobile-card"
                    >
                        <div class="admin-events-mobile-top">
                            <div class="min-w-0">
                                <p class="admin-events-title">{{ event.title }}</p>
                                <p class="admin-events-sub">{{ event.client_name }}</p>
                                <div
                                    v-if="event.report_summary"
                                    class="admin-event-report-summary"
                                >
                                    <span>{{ event.report_summary.active_imports_count }} arquivo(s)</span>
                                    <span>{{ event.report_summary.active_rows_count }} linhas</span>
                                    <span>{{ formatMoney(event.report_summary.total) }}</span>
                                </div>
                                <p
                                    v-if="event.report_summary?.last_imported_at"
                                    class="admin-event-report-meta"
                                >
                                    Ultimo import: {{ formatDate(event.report_summary.last_imported_at) }}
                                </p>
                                <p
                                    v-else
                                    class="admin-event-report-empty"
                                >
                                    Sem relatorio importado.
                                </p>
                            </div>
                            <span
                                class="status-pill shrink-0"
                                :class="event.is_active ? 'success' : 'neutral'"
                            >
                                {{ event.is_active ? 'Ativo' : 'Inativo' }}
                            </span>
                        </div>

                        <div class="admin-events-mobile-grid">
                            <div class="admin-events-mobile-item admin-events-mobile-item-full">
                                <p class="admin-events-mobile-label">Data</p>
                                <p class="admin-events-mobile-value">
                                    {{ formatDate(event.event_date) }}
                                </p>
                            </div>

                            <div
                                v-if="event.description"
                                class="admin-events-mobile-item admin-events-mobile-item-full"
                            >
                                <p class="admin-events-mobile-label">Descrição</p>
                                <p class="admin-events-mobile-value">{{ event.description }}</p>
                            </div>
                        </div>

                        <div class="admin-events-actions">
                            <Link
                                :href="route('admin.events.dashboard', event.id)"
                                class="admin-event-icon-btn"
                                title="Ver dashboard do evento"
                                aria-label="Ver dashboard do evento"
                            >
                                <svg
                                    class="h-4 w-4"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <path d="M3 12h4l3-8 4 16 3-8h4" />
                                </svg>
                            </Link>

                            <button
                                type="button"
                                class="admin-event-icon-btn"
                                title="Importar relatorio"
                                aria-label="Importar relatorio"
                                @click="openImportReportModal(event)"
                            >
                                <svg
                                    class="h-4 w-4"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <path d="M12 3v12" />
                                    <path d="m7 10 5 5 5-5" />
                                    <path d="M4 21h16" />
                                </svg>
                            </button>

                            <button
                                type="button"
                                class="admin-event-icon-btn"
                                title="Editar evento"
                                aria-label="Editar evento"
                                @click="openEditEventModal(event)"
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
                                class="admin-event-icon-btn"
                                :class="{ warning: event.is_active, success: !event.is_active }"
                                :title="event.is_active ? 'Desativar evento' : 'Ativar evento'"
                                :aria-label="event.is_active ? 'Desativar evento' : 'Ativar evento'"
                                @click="toggleEventStatus(event)"
                            >
                                <svg
                                    v-if="event.is_active"
                                    class="h-4 w-4"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <path d="M12 2v10" />
                                    <path d="M18.4 6.6A9 9 0 1 1 5.6 6.6" />
                                </svg>
                                <svg
                                    v-else
                                    class="h-4 w-4"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <path d="M8 12l2.5 2.5L16 9" />
                                    <circle cx="12" cy="12" r="9" />
                                </svg>
                            </button>

                            <button
                                type="button"
                                class="admin-event-icon-btn danger"
                                title="Deletar evento"
                                aria-label="Deletar evento"
                                @click="deleteEvent(event)"
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
                    </article>

                    <div v-if="!events.length" class="admin-events-mobile-empty">
                        Nenhum evento cadastrado.
                    </div>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="admin-events-table">
                        <thead>
                            <tr>
                                <th>Evento</th>
                                <th>Cliente</th>
                                <th>Data</th>
                                <th>Status</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="event in events" :key="event.id">
                                <td>
                                    <p class="admin-events-title">{{ event.title }}</p>
                                    <p v-if="event.description" class="admin-events-sub">
                                        {{ event.description }}
                                    </p>
                                    <div
                                        v-if="event.report_summary"
                                        class="admin-event-report-summary"
                                    >
                                        <span>{{ event.report_summary.active_imports_count }} arquivo(s)</span>
                                        <span>{{ event.report_summary.active_rows_count }} linhas</span>
                                        <span>{{ formatMoney(event.report_summary.total) }}</span>
                                    </div>
                                    <p
                                        v-if="event.report_summary?.last_imported_at"
                                        class="admin-event-report-meta"
                                    >
                                        Ultimo import: {{ formatDate(event.report_summary.last_imported_at) }}
                                    </p>
                                    <p
                                        v-else
                                        class="admin-event-report-empty"
                                    >
                                        Sem relatorio importado.
                                    </p>
                                </td>
                                <td class="admin-events-text">
                                    {{ event.client_name }}
                                </td>
                                <td class="admin-events-text">
                                    {{ formatDate(event.event_date) }}
                                </td>
                                <td class="admin-events-text">
                                    <span
                                        class="status-pill"
                                        :class="event.is_active ? 'success' : 'neutral'"
                                    >
                                        {{ event.is_active ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="admin-events-actions">
                                        <Link
                                            :href="route('admin.events.dashboard', event.id)"
                                            class="admin-event-icon-btn"
                                            title="Ver dashboard do evento"
                                            aria-label="Ver dashboard do evento"
                                        >
                                            <svg
                                                class="h-4 w-4"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                            >
                                                <path d="M3 12h4l3-8 4 16 3-8h4" />
                                            </svg>
                                        </Link>

                                        <button
                                            type="button"
                                            class="admin-event-icon-btn"
                                            title="Importar relatorio"
                                            aria-label="Importar relatorio"
                                            @click="openImportReportModal(event)"
                                        >
                                            <svg
                                                class="h-4 w-4"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                            >
                                                <path d="M12 3v12" />
                                                <path d="m7 10 5 5 5-5" />
                                                <path d="M4 21h16" />
                                            </svg>
                                        </button>

                                        <button
                                            type="button"
                                            class="admin-event-icon-btn"
                                            title="Editar evento"
                                            aria-label="Editar evento"
                                            @click="openEditEventModal(event)"
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
                                            class="admin-event-icon-btn"
                                            :class="{ warning: event.is_active, success: !event.is_active }"
                                            :title="event.is_active ? 'Desativar evento' : 'Ativar evento'"
                                            :aria-label="event.is_active ? 'Desativar evento' : 'Ativar evento'"
                                            @click="toggleEventStatus(event)"
                                        >
                                            <svg
                                                v-if="event.is_active"
                                                class="h-4 w-4"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                            >
                                                <path d="M12 2v10" />
                                                <path d="M18.4 6.6A9 9 0 1 1 5.6 6.6" />
                                            </svg>
                                            <svg
                                                v-else
                                                class="h-4 w-4"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                            >
                                                <path d="M8 12l2.5 2.5L16 9" />
                                                <circle cx="12" cy="12" r="9" />
                                            </svg>
                                        </button>

                                        <button
                                            type="button"
                                            class="admin-event-icon-btn danger"
                                            title="Deletar evento"
                                            aria-label="Deletar evento"
                                            @click="deleteEvent(event)"
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
                            <tr v-if="!events.length">
                                <td colspan="5" class="py-8 text-center text-sm">
                                    <span class="dash-muted-text">
                                        Nenhum evento cadastrado.
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <Modal
            :show="showCreateEventModal"
            max-width="2xl"
            @close="closeCreateEventModal"
        >
            <form class="dash-modal" @submit.prevent="submitCreateEvent">
                <div class="dash-modal-header">
                    <h3 class="dash-modal-title">Novo evento</h3>
                    <button
                        type="button"
                        class="dash-modal-close"
                        @click="closeCreateEventModal"
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
                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="event_client_create">
                            Cliente
                        </label>
                        <select
                            id="event_client_create"
                            v-model="createEventForm.client_id"
                            class="dash-modal-input"
                            required
                        >
                            <option disabled value="">
                                Selecione um cliente
                            </option>
                            <option
                                v-for="client in props.clients"
                                :key="client.id"
                                :value="client.id"
                            >
                                {{ client.name }}{{ client.business_name ? ` - ${client.business_name}` : '' }}
                            </option>
                        </select>
                        <p
                            v-if="createEventForm.errors.client_id"
                            class="dash-modal-error"
                        >
                            {{ createEventForm.errors.client_id }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="event_title_create">
                            Título
                        </label>
                        <input
                            id="event_title_create"
                            v-model="createEventForm.title"
                            class="dash-modal-input"
                            type="text"
                            required
                        />
                        <p
                            v-if="createEventForm.errors.title"
                            class="dash-modal-error"
                        >
                            {{ createEventForm.errors.title }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="event_date_create">
                            Data do evento
                        </label>
                        <input
                            id="event_date_create"
                            v-model="createEventForm.event_date"
                            class="dash-modal-input"
                            type="datetime-local"
                            required
                        />
                        <p
                            v-if="createEventForm.errors.event_date"
                            class="dash-modal-error"
                        >
                            {{ createEventForm.errors.event_date }}
                        </p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="event_description_create">
                            Descrição (opcional)
                        </label>
                        <textarea
                            id="event_description_create"
                            v-model="createEventForm.description"
                            class="dash-modal-input"
                            rows="4"
                        />
                        <p
                            v-if="createEventForm.errors.description"
                            class="dash-modal-error"
                        >
                            {{ createEventForm.errors.description }}
                        </p>
                    </div>
                </div>

                <div class="dash-modal-actions">
                    <button
                        type="button"
                        class="dash-modal-cancel"
                        @click="closeCreateEventModal"
                    >
                        Cancelar
                    </button>

                    <button
                        type="submit"
                        class="dash-action-button dash-action-button-inline"
                        :disabled="createEventForm.processing"
                        :class="{ 'opacity-60': createEventForm.processing }"
                    >
                        Salvar evento
                    </button>
                </div>
            </form>
        </Modal>

        <Modal
            :show="showEditEventModal"
            max-width="2xl"
            @close="closeEditEventModal"
        >
            <form class="dash-modal" @submit.prevent="submitEditEvent">
                <div class="dash-modal-header">
                    <h3 class="dash-modal-title">Editar evento</h3>
                    <button
                        type="button"
                        class="dash-modal-close"
                        @click="closeEditEventModal"
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
                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="event_client_edit">
                            Cliente
                        </label>
                        <select
                            id="event_client_edit"
                            v-model="editEventForm.client_id"
                            class="dash-modal-input"
                            required
                        >
                            <option disabled value="">
                                Selecione um cliente
                            </option>
                            <option
                                v-for="client in props.clients"
                                :key="client.id"
                                :value="client.id"
                            >
                                {{ client.name }}{{ client.business_name ? ` - ${client.business_name}` : '' }}
                            </option>
                        </select>
                        <p
                            v-if="editEventForm.errors.client_id"
                            class="dash-modal-error"
                        >
                            {{ editEventForm.errors.client_id }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="event_title_edit">
                            Título
                        </label>
                        <input
                            id="event_title_edit"
                            v-model="editEventForm.title"
                            class="dash-modal-input"
                            type="text"
                            required
                        />
                        <p
                            v-if="editEventForm.errors.title"
                            class="dash-modal-error"
                        >
                            {{ editEventForm.errors.title }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="event_date_edit">
                            Data do evento
                        </label>
                        <input
                            id="event_date_edit"
                            v-model="editEventForm.event_date"
                            class="dash-modal-input"
                            type="datetime-local"
                            required
                        />
                        <p
                            v-if="editEventForm.errors.event_date"
                            class="dash-modal-error"
                        >
                            {{ editEventForm.errors.event_date }}
                        </p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="event_description_edit">
                            Descrição (opcional)
                        </label>
                        <textarea
                            id="event_description_edit"
                            v-model="editEventForm.description"
                            class="dash-modal-input"
                            rows="4"
                        />
                        <p
                            v-if="editEventForm.errors.description"
                            class="dash-modal-error"
                        >
                            {{ editEventForm.errors.description }}
                        </p>
                    </div>
                </div>

                <div class="dash-modal-actions">
                    <button
                        type="button"
                        class="dash-modal-cancel"
                        @click="closeEditEventModal"
                    >
                        Cancelar
                    </button>

                    <button
                        type="submit"
                        class="dash-action-button dash-action-button-inline"
                        :disabled="editEventForm.processing"
                        :class="{ 'opacity-60': editEventForm.processing }"
                    >
                        Atualizar evento
                    </button>
                </div>
            </form>
        </Modal>

        <Modal
            :show="showImportReportModal"
            max-width="2xl"
            @close="closeImportReportModal"
        >
            <form class="dash-modal" @submit.prevent="submitReportImport">
                <div class="dash-modal-header">
                    <div>
                        <h3 class="dash-modal-title">Importar relatorio do evento</h3>
                        <p
                            v-if="selectedReportEvent"
                            class="admin-event-modal-subtitle"
                        >
                            {{ selectedReportEvent.title }} - {{ selectedReportEvent.client_name }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="dash-modal-close"
                        @click="closeImportReportModal"
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

                <div
                    v-if="selectedReportEvent?.report_summary"
                    class="admin-event-import-highlight"
                >
                    <p class="admin-event-import-highlight-title">
                        Relatorios ativos agora
                    </p>
                    <div class="admin-event-report-summary">
                        <span>{{ selectedReportEvent.report_summary.active_imports_count }} arquivo(s)</span>
                        <span>{{ selectedReportEvent.report_summary.active_rows_count }} linhas</span>
                        <span>{{ formatMoney(selectedReportEvent.report_summary.total) }}</span>
                    </div>
                    <p
                        v-if="selectedReportEvent.report_summary.last_imported_at"
                        class="admin-event-report-meta"
                    >
                        Ultimo arquivo: {{ selectedReportEvent.report_summary.last_filename }} -
                        {{ formatDate(selectedReportEvent.report_summary.last_imported_at) }}
                    </p>
                </div>

                <div class="dash-modal-grid">
                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="event_report_file">
                            Arquivo XLS ou XLSX
                        </label>
                        <input
                            id="event_report_file"
                            ref="reportFileInput"
                            class="dash-modal-input"
                            type="file"
                            accept=".xls,.xlsx"
                            @change="handleReportFileChange"
                        />
                        <p class="admin-event-input-hint">
                            O layout atual do import usa a mesma base da planilha enviada para o evento.
                        </p>
                        <p
                            v-if="reportImportForm.errors.report_file"
                            class="dash-modal-error"
                        >
                            {{ reportImportForm.errors.report_file }}
                        </p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="event_report_strategy">
                            Estrategia de importacao
                        </label>
                        <select
                            id="event_report_strategy"
                            v-model="reportImportForm.import_strategy"
                            class="dash-modal-input"
                        >
                            <option value="replace">
                                Substituir relatorios ativos
                            </option>
                            <option value="sum">
                                Somar com relatorios ativos
                            </option>
                        </select>
                        <p class="admin-event-input-hint">
                            <strong>Substituir</strong> desativa os uploads ativos anteriores. <strong>Somar</strong>
                            mantem os anteriores ativos e acrescenta os novos dados ao evento.
                        </p>
                        <p
                            v-if="reportImportForm.errors.import_strategy"
                            class="dash-modal-error"
                        >
                            {{ reportImportForm.errors.import_strategy }}
                        </p>
                    </div>
                </div>

                <div class="dash-modal-actions">
                    <button
                        type="button"
                        class="dash-modal-cancel"
                        @click="closeImportReportModal"
                    >
                        Cancelar
                    </button>

                    <button
                        type="submit"
                        class="dash-action-button dash-action-button-inline"
                        :disabled="reportImportForm.processing"
                        :class="{ 'opacity-60': reportImportForm.processing }"
                    >
                        Importar relatorio
                    </button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
