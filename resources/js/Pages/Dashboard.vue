<script setup lang="ts">
import Modal from '@/Components/Modal.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Stats {
    clients: number;
    events: number;
    upcoming: number;
}

interface AdminClient {
    id: number;
    name: string;
    business_name: string | null;
    events_count: number;
}

interface AdminEvent {
    id: number;
    title: string;
    event_date: string;
    client_name: string;
}

interface ClientData {
    id: number;
    name: string;
    business_name: string | null;
    address: string;
    phone: string;
}

interface ClientEvent {
    id: number;
    title: string;
    description: string | null;
    event_date: string;
}

const props = defineProps<{
    type: 'admin' | 'client';
    stats?: Stats;
    recentClients?: AdminClient[];
    upcomingEvents?: AdminEvent[];
    client?: ClientData;
    events?: ClientEvent[];
}>();

const formatDate = (date: string) =>
    new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(date));

const monthLabels = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun'];

const monthlySeries = computed(() => {
    const totalEvents = Math.max(props.stats?.events ?? 24, 24);

    return [
        Math.round(totalEvents * 0.25),
        Math.round(totalEvents * 0.38),
        Math.round(totalEvents * 0.34),
        Math.round(totalEvents * 0.52),
        Math.round(totalEvents * 0.48),
        Math.round(totalEvents * 0.67),
    ];
});

const chartMax = computed(() => Math.max(...monthlySeries.value, 1));

const chartPoints = computed(() => {
    const width = 560;
    const height = 180;
    const stepX = width / (monthlySeries.value.length - 1);

    return monthlySeries.value.map((value, index) => ({
        label: monthLabels[index],
        value,
        x: Number((index * stepX).toFixed(2)),
        y: Number(
            (height - (value / chartMax.value) * (height - 10)).toFixed(2),
        ),
    }));
});

const chartPath = computed(() =>
    chartPoints.value
        .map((point, index) =>
            `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`,
        )
        .join(' '),
);

const chartAreaPath = computed(
    () => `${chartPath.value} L 560 180 L 0 180 Z`,
);

const lastChartPoint = computed(
    () => chartPoints.value[chartPoints.value.length - 1],
);

const showCreateClientModal = ref(false);

const createClientForm = useForm({
    name: '',
    business_name: '',
    address: '',
    phone: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const openCreateClientModal = () => {
    showCreateClientModal.value = true;
};

const closeCreateClientModal = () => {
    showCreateClientModal.value = false;
    createClientForm.clearErrors();
};

const submitCreateClient = () => {
    createClientForm.post(route('admin.clients.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCreateClientModal.value = false;
            createClientForm.reset();
        },
    });
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="dash-page-title">
                Dashboard
            </h2>
        </template>

        <div class="dash-page">
            <div v-if="props.type === 'admin'" class="dash-grid">
                <section class="dash-card dash-card-full">
                    <h3 class="dash-card-title">Visão Geral do Mês</h3>

                    <div class="dash-chart-wrap">
                        <svg
                            viewBox="0 0 560 180"
                            preserveAspectRatio="none"
                            class="h-52 w-full"
                        >
                            <defs>
                                <linearGradient
                                    id="chartFill"
                                    x1="0%"
                                    y1="0%"
                                    x2="0%"
                                    y2="100%"
                                >
                                    <stop offset="0%" stop-color="#2c8bff" stop-opacity="0.35" />
                                    <stop offset="100%" stop-color="#2c8bff" stop-opacity="0.02" />
                                </linearGradient>
                            </defs>

                            <path
                                :d="chartAreaPath"
                                fill="url(#chartFill)"
                            />
                            <path
                                :d="chartPath"
                                fill="none"
                                stroke="#1f7cf4"
                                stroke-width="4"
                                stroke-linecap="round"
                            />

                            <circle
                                v-if="lastChartPoint"
                                :cx="lastChartPoint.x"
                                :cy="lastChartPoint.y"
                                r="6"
                                fill="#0ea5a4"
                                stroke="#ffffff"
                                stroke-width="3"
                            />
                        </svg>

                        <span class="dash-chart-badge">
                            #{{ String(props.stats?.events ?? 0).padStart(5, '0') }}
                        </span>
                    </div>

                    <div class="dash-axis">
                        <span v-for="label in monthLabels" :key="label">{{ label }}</span>
                    </div>

                    <p class="dash-legend">Visão Geral do Mês</p>
                </section>

                <section class="dash-card">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="dash-card-title mb-0">Clientes Recentes</h3>
                        <button
                            type="button"
                            @click="openCreateClientModal"
                            class="dash-action-button dash-action-button-inline"
                        >
                            Novo Cliente
                        </button>
                    </div>

                    <ul class="dash-list">
                        <li
                            v-for="client in props.recentClients ?? []"
                            :key="client.id"
                            class="dash-list-item"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="dash-main-text font-semibold">{{ client.name }}</p>
                                    <p class="dash-muted-text text-sm">
                                        {{ client.business_name || 'Sem nome comercial' }}
                                    </p>
                                    <p class="dash-muted-text text-xs">
                                        {{ client.events_count }} evento(s)
                                    </p>
                                </div>
                                <span
                                    class="status-pill"
                                    :class="client.events_count > 0 ? 'success' : 'neutral'"
                                >
                                    {{ client.events_count > 0 ? 'Ativo' : 'Novo' }}
                                </span>
                            </div>
                        </li>
                        <li
                            v-if="!(props.recentClients?.length)"
                            class="dash-list-item dash-muted-text text-sm"
                        >
                            Nenhum cliente cadastrado.
                        </li>
                    </ul>
                </section>

                <div class="dash-stack">

                    <section class="dash-card">
                        <h3 class="dash-card-title">Total Projetos</h3>
                        <div class="dash-kpi-grid">
                            <div class="dash-kpi-item">
                                <span>Clientes</span>
                                <strong>{{ props.stats?.clients ?? 0 }}</strong>
                            </div>
                            <div class="dash-kpi-item">
                                <span>Total Eventos</span>
                                <strong>{{ props.stats?.events ?? 0 }}</strong>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <div v-else class="grid gap-6 lg:grid-cols-2">
                <section class="dash-card">
                    <h3 class="dash-card-title">Dados do Cliente</h3>
                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="dash-muted-text font-medium">Nome</dt>
                            <dd class="dash-main-text font-semibold">{{ props.client?.name }}</dd>
                        </div>
                        <div>
                            <dt class="dash-muted-text font-medium">Nome comercial</dt>
                            <dd class="dash-soft-text">{{ props.client?.business_name || 'Não informado' }}</dd>
                        </div>
                        <div>
                            <dt class="dash-muted-text font-medium">Endereço</dt>
                            <dd class="dash-soft-text">{{ props.client?.address }}</dd>
                        </div>
                        <div>
                            <dt class="dash-muted-text font-medium">Telefone</dt>
                            <dd class="dash-soft-text">{{ props.client?.phone }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="dash-card">
                    <h3 class="dash-card-title">Meus Eventos</h3>
                    <ul class="dash-list">
                        <li
                            v-for="event in props.events ?? []"
                            :key="event.id"
                            class="dash-list-item"
                        >
                            <p class="dash-main-text font-semibold">{{ event.title }}</p>
                            <p
                                v-if="event.description"
                                class="dash-muted-text text-sm"
                            >
                                {{ event.description }}
                            </p>
                            <p class="dash-muted-text text-xs">
                                {{ formatDate(event.event_date) }}
                            </p>
                        </li>
                        <li
                            v-if="!(props.events?.length)"
                            class="dash-list-item dash-muted-text text-sm"
                        >
                            Nenhum evento cadastrado para você.
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <Modal
            :show="showCreateClientModal"
            max-width="2xl"
            @close="closeCreateClientModal"
        >
            <form class="dash-modal" @submit.prevent="submitCreateClient">
                <div class="dash-modal-header">
                    <h3 class="dash-modal-title">Novo Cliente</h3>
                    <button
                        type="button"
                        class="dash-modal-close"
                        @click="closeCreateClientModal"
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
                        <label class="dash-modal-label" for="client_name">
                            Nome
                        </label>
                        <input
                            id="client_name"
                            v-model="createClientForm.name"
                            class="dash-modal-input"
                            type="text"
                            required
                            autofocus
                        />
                        <p
                            v-if="createClientForm.errors.name"
                            class="dash-modal-error"
                        >
                            {{ createClientForm.errors.name }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="client_business_name">
                            Nome comercial (opcional)
                        </label>
                        <input
                            id="client_business_name"
                            v-model="createClientForm.business_name"
                            class="dash-modal-input"
                            type="text"
                        />
                        <p
                            v-if="createClientForm.errors.business_name"
                            class="dash-modal-error"
                        >
                            {{ createClientForm.errors.business_name }}
                        </p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="client_address">
                            Endereço
                        </label>
                        <input
                            id="client_address"
                            v-model="createClientForm.address"
                            class="dash-modal-input"
                            type="text"
                            required
                        />
                        <p
                            v-if="createClientForm.errors.address"
                            class="dash-modal-error"
                        >
                            {{ createClientForm.errors.address }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="client_phone">
                            Telefone
                        </label>
                        <input
                            id="client_phone"
                            v-model="createClientForm.phone"
                            class="dash-modal-input"
                            type="text"
                            required
                        />
                        <p
                            v-if="createClientForm.errors.phone"
                            class="dash-modal-error"
                        >
                            {{ createClientForm.errors.phone }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="client_email">
                            Usuário (e-mail)
                        </label>
                        <input
                            id="client_email"
                            v-model="createClientForm.email"
                            class="dash-modal-input"
                            type="email"
                            required
                        />
                        <p
                            v-if="createClientForm.errors.email"
                            class="dash-modal-error"
                        >
                            {{ createClientForm.errors.email }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="client_password">
                            Senha
                        </label>
                        <input
                            id="client_password"
                            v-model="createClientForm.password"
                            class="dash-modal-input"
                            type="password"
                            required
                        />
                        <p
                            v-if="createClientForm.errors.password"
                            class="dash-modal-error"
                        >
                            {{ createClientForm.errors.password }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="client_password_confirmation">
                            Confirmar senha
                        </label>
                        <input
                            id="client_password_confirmation"
                            v-model="createClientForm.password_confirmation"
                            class="dash-modal-input"
                            type="password"
                            required
                        />
                    </div>
                </div>

                <div class="dash-modal-actions">
                    <button
                        type="button"
                        class="dash-modal-cancel"
                        @click="closeCreateClientModal"
                    >
                        Cancelar
                    </button>

                    <button
                        type="submit"
                        class="dash-action-button dash-action-button-inline"
                        :disabled="createClientForm.processing"
                        :class="{ 'opacity-60': createClientForm.processing }"
                    >
                        Salvar cliente
                    </button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
