<script setup lang="ts">
import DashboardPageEditor from '@/Components/DashboardPageEditor.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { confirmAction, showErrorToast, showSuccessToast } from '@/lib/swal';
import type {
    DashboardConfiguration,
    DashboardPreset,
} from '@/types/dashboard-configuration';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

interface EventMeta {
    id: number;
    title: string;
    client_name: string;
    event_date: string;
}

const props = defineProps<{
    event: EventMeta;
    configuration: DashboardConfiguration;
    presets: DashboardPreset[];
    updateUrl: string;
    dashboardUrl: string;
}>();

const isSaving = ref(false);

const formatDate = (date: string) => new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: 'long',
    year: 'numeric',
}).format(new Date(date));

const publishConfiguration = (configuration: DashboardConfiguration) => {
    if (isSaving.value) {
        return;
    }

    isSaving.value = true;
    router.patch(props.updateUrl, {
        configuration: JSON.parse(JSON.stringify(configuration)),
    }, {
        preserveScroll: true,
        onSuccess: () => {
            void showSuccessToast('Apresentação publicada para este evento.');
        },
        onError: (errors) => {
            void showErrorToast(
                (errors.configuration as string | undefined)
                ?? 'Não foi possível publicar a apresentação.',
            );
        },
        onFinish: () => {
            isSaving.value = false;
        },
    });
};

const restoreDefault = async () => {
    if (isSaving.value) {
        return;
    }

    const confirmed = await confirmAction({
        title: 'Restaurar o layout padrão?',
        text: 'As labels, a ordem e os itens ocultos deste evento voltarão ao padrão.',
        confirmButtonText: 'Restaurar padrão',
    });

    if (!confirmed) {
        return;
    }

    isSaving.value = true;
    router.patch(props.updateUrl, { configuration: null }, {
        preserveScroll: true,
        onSuccess: () => {
            void showSuccessToast('Layout padrão restaurado.');
        },
        onError: () => {
            void showErrorToast('Não foi possível restaurar o layout padrão.');
        },
        onFinish: () => {
            isSaving.value = false;
        },
    });
};
</script>

<template>
    <Head :title="`Editar dashboard - ${props.event.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="dashboard-configuration-toolbar">
                <div>
                    <span>Apresentação do cliente</span>
                    <strong>{{ props.event.title }}</strong>
                </div>
                <Link :href="props.dashboardUrl" class="dashboard-configuration-back">
                    Ver dashboard
                </Link>
            </div>
        </template>

        <main class="dashboard-configuration-page">
            <section class="dashboard-configuration-intro">
                <div>
                    <span>Editor administrativo</span>
                    <h1>Personalizar dashboard</h1>
                    <p>
                        Defina labels, visibilidade e ordem apenas para este evento.
                        Os valores e os cálculos do relatório não são alterados.
                    </p>
                </div>
                <dl>
                    <div>
                        <dt>Cliente</dt>
                        <dd>{{ props.event.client_name }}</dd>
                    </div>
                    <div>
                        <dt>Evento</dt>
                        <dd>{{ formatDate(props.event.event_date) }}</dd>
                    </div>
                </dl>
            </section>

            <DashboardPageEditor
                embedded
                :configuration="props.configuration"
                :presets="props.presets"
                :saving="isSaving"
                @save="publishConfiguration"
                @reset="restoreDefault"
                @close="router.visit(props.dashboardUrl)"
            />
        </main>
    </AuthenticatedLayout>
</template>

<style scoped>
.dashboard-configuration-toolbar {
    display: flex;
    width: 100%;
    min-width: 0;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.dashboard-configuration-toolbar span,
.dashboard-configuration-toolbar strong {
    display: block;
}

.dashboard-configuration-toolbar span {
    color: var(--accent-strong);
    font-size: 0.65rem;
    font-weight: 800;
    letter-spacing: 0.15em;
    text-transform: uppercase;
}

.dashboard-configuration-toolbar strong {
    margin-top: 0.2rem;
    overflow: hidden;
    color: var(--text-main);
    font-size: 1rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.dashboard-configuration-back {
    flex: none;
    border: 1px solid var(--card-border);
    border-radius: 0.8rem;
    padding: 0.7rem 1rem;
    color: var(--text-main);
    background: var(--card-bg);
    font-size: 0.78rem;
    font-weight: 800;
}

.dashboard-configuration-page {
    display: grid;
    gap: 1.5rem;
    margin: 0 auto;
    max-width: 92rem;
    padding: 2rem;
}

.dashboard-configuration-intro {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: end;
    gap: 2rem;
    border: 1px solid var(--card-border);
    border-radius: 1.5rem;
    padding: 1.5rem;
    background:
        radial-gradient(circle at 92% 8%, color-mix(in srgb, var(--accent) 14%, transparent), transparent 34%),
        var(--card-bg);
}

.dashboard-configuration-intro > div > span {
    color: var(--accent-strong);
    font-size: 0.68rem;
    font-weight: 850;
    letter-spacing: 0.16em;
    text-transform: uppercase;
}

.dashboard-configuration-intro h1 {
    margin: 0.35rem 0 0;
    color: var(--text-main);
    font-size: clamp(1.65rem, 3vw, 2.4rem);
    font-weight: 850;
}

.dashboard-configuration-intro p {
    max-width: 46rem;
    margin: 0.55rem 0 0;
    color: var(--text-muted);
    line-height: 1.65;
}

.dashboard-configuration-intro dl {
    display: grid;
    grid-template-columns: repeat(2, minmax(9rem, 1fr));
    gap: 0.75rem;
}

.dashboard-configuration-intro dl > div {
    border: 1px solid var(--line-color);
    border-radius: 1rem;
    padding: 0.85rem 1rem;
    background: color-mix(in srgb, var(--card-bg) 88%, var(--accent) 12%);
}

.dashboard-configuration-intro dt {
    color: var(--text-muted);
    font-size: 0.62rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.dashboard-configuration-intro dd {
    margin: 0.3rem 0 0;
    color: var(--text-main);
    font-size: 0.82rem;
    font-weight: 750;
}

@media (max-width: 900px) {
    .dashboard-configuration-page {
        padding: 1rem;
    }

    .dashboard-configuration-intro {
        grid-template-columns: 1fr;
    }

    .dashboard-configuration-intro dl {
        grid-template-columns: 1fr;
    }
}
</style>
