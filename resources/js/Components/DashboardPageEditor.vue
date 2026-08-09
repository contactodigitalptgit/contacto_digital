<script setup lang="ts">
import type {
    DashboardConfiguration,
    DashboardConfigurationItem,
    DashboardMetricGroup,
    DashboardPreset,
} from '@/types/dashboard-configuration';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(defineProps<{
    configuration: DashboardConfiguration;
    presets: DashboardPreset[];
    saving: boolean;
    embedded?: boolean;
}>(), {
    embedded: false,
});

const emit = defineEmits<{
    preview: [configuration: DashboardConfiguration];
    save: [configuration: DashboardConfiguration];
    reset: [];
    close: [];
}>();

type EditorTab = 'sections' | 'blocks' | 'metrics';

const cloneConfiguration = (configuration: DashboardConfiguration): DashboardConfiguration =>
    JSON.parse(JSON.stringify(configuration)) as DashboardConfiguration;

const draft = ref<DashboardConfiguration>(cloneConfiguration(props.configuration));
const activeTab = ref<EditorTab>('sections');
const selectedPreset = ref(props.configuration.preset === 'custom' ? 'complete' : props.configuration.preset);
let keepPresetOnNextChange = false;

const summaryBlocks = computed(() => draft.value.blocks.filter((block) => block.area === 'summary'));
const chartBlocks = computed(() => draft.value.blocks.filter((block) => block.area === 'charts'));
const metricGroups: Array<{ key: DashboardMetricGroup; label: string }> = [
    { key: 'movement', label: 'Leitura financeira' },
    { key: 'payments', label: 'Formas de pagamento' },
    { key: 'top_up', label: 'Fluxo ZT - Card' },
    { key: 'operations', label: 'Indicadores operacionais' },
];

watch(draft, (configuration) => {
    if (keepPresetOnNextChange) {
        keepPresetOnNextChange = false;
    } else if (configuration.preset !== 'custom') {
        configuration.preset = 'custom';
    }

    emit('preview', cloneConfiguration(configuration));
}, { deep: true, flush: 'sync' });

function applyConfiguration(configuration: DashboardConfiguration): void {
    keepPresetOnNextChange = true;
    draft.value = cloneConfiguration(configuration);
}

function applyPreset(): void {
    const preset = props.presets.find((item) => item.key === selectedPreset.value);

    if (preset) {
        applyConfiguration(preset.configuration);
    }
}

function itemsForGroup(group: DashboardMetricGroup): DashboardConfigurationItem[] {
    return draft.value.metrics.filter((metric) => metric.group === group);
}

function moveItem(
    collection: DashboardConfigurationItem[],
    key: string,
    direction: -1 | 1,
    scope?: { field: 'area' | 'group'; value: string },
): void {
    const candidateIndexes = collection
        .map((item, index) => ({ item, index }))
        .filter(({ item }) => !scope || item[scope.field] === scope.value)
        .map(({ index }) => index);
    const currentCollectionIndex = collection.findIndex((item) => item.key === key);
    const currentScopedIndex = candidateIndexes.indexOf(currentCollectionIndex);
    const targetCollectionIndex = candidateIndexes[currentScopedIndex + direction];

    if (currentCollectionIndex < 0 || targetCollectionIndex === undefined) {
        return;
    }

    [collection[currentCollectionIndex], collection[targetCollectionIndex]] = [
        collection[targetCollectionIndex],
        collection[currentCollectionIndex],
    ];
}

function isFirst(
    collection: DashboardConfigurationItem[],
    key: string,
    scope?: { field: 'area' | 'group'; value: string },
): boolean {
    return collection.filter((item) => !scope || item[scope.field] === scope.value)[0]?.key === key;
}

function isLast(
    collection: DashboardConfigurationItem[],
    key: string,
    scope?: { field: 'area' | 'group'; value: string },
): boolean {
    return collection.filter((item) => !scope || item[scope.field] === scope.value).at(-1)?.key === key;
}

function handleKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && !props.saving && !props.embedded) {
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown));
</script>

<template>
    <aside
        class="dashboard-page-editor"
        :class="{ 'is-embedded': props.embedded }"
        aria-label="Editor da apresentação do dashboard"
    >
        <header class="dashboard-page-editor__header">
            <div>
                <span>Modo administrativo</span>
                <h2>Editar página</h2>
                <p>Personalize a apresentação deste evento e publique apenas quando estiver pronta.</p>
            </div>
            <button v-if="!props.embedded" type="button" aria-label="Fechar editor" :disabled="props.saving" @click="emit('close')">×</button>
        </header>

        <section class="dashboard-page-editor__preset">
            <label for="dashboard-preset">Começar com um preset</label>
            <div>
                <select id="dashboard-preset" v-model="selectedPreset">
                    <option v-for="preset in props.presets" :key="preset.key" :value="preset.key">
                        {{ preset.label }}
                    </option>
                </select>
                <button type="button" @click="applyPreset">Aplicar</button>
            </div>
            <p>{{ props.presets.find((preset) => preset.key === selectedPreset)?.description }}</p>
        </section>

        <nav class="dashboard-page-editor__tabs" aria-label="Áreas do editor">
            <button type="button" :class="{ 'is-active': activeTab === 'sections' }" @click="activeTab = 'sections'">Páginas</button>
            <button type="button" :class="{ 'is-active': activeTab === 'blocks' }" @click="activeTab = 'blocks'">Blocos</button>
            <button type="button" :class="{ 'is-active': activeTab === 'metrics' }" @click="activeTab = 'metrics'">Indicadores</button>
        </nav>

        <div class="dashboard-page-editor__body">
            <section v-if="activeTab === 'sections'" class="dashboard-page-editor__list">
                <div class="dashboard-page-editor__intro">
                    <strong>Menu e páginas</strong>
                    <span>Escolha o que o cliente vê e a ordem do menu.</span>
                </div>
                <article v-for="section in draft.sections" :key="section.key" class="dashboard-page-editor__item">
                    <div class="dashboard-page-editor__item-toolbar">
                        <label class="dashboard-page-editor__visibility">
                            <input v-model="section.visible" type="checkbox" />
                            <span>{{ section.visible ? 'Visível' : 'Oculta' }}</span>
                        </label>
                        <div class="dashboard-page-editor__order">
                            <button
                                type="button"
                                aria-label="Mover para cima"
                                :disabled="isFirst(draft.sections, section.key)"
                                @click="moveItem(draft.sections, section.key, -1)"
                            >↑</button>
                            <button
                                type="button"
                                aria-label="Mover para baixo"
                                :disabled="isLast(draft.sections, section.key)"
                                @click="moveItem(draft.sections, section.key, 1)"
                            >↓</button>
                        </div>
                    </div>
                    <label>Label<input v-model.trim="section.label" maxlength="80" /></label>
                    <label>Texto auxiliar<input v-model.trim="section.helper" maxlength="120" /></label>
                </article>
            </section>

            <section v-else-if="activeTab === 'blocks'" class="dashboard-page-editor__list">
                <div class="dashboard-page-editor__intro">
                    <strong>Blocos da página</strong>
                    <span>Reordene ou oculte áreas inteiras sem alterar os dados.</span>
                </div>

                <template v-for="group in [{ key: 'summary', label: 'Resumo' }, { key: 'charts', label: 'Gráficos' }]" :key="group.key">
                    <h3>{{ group.label }}</h3>
                    <article
                        v-for="block in group.key === 'summary' ? summaryBlocks : chartBlocks"
                        :key="block.key"
                        class="dashboard-page-editor__item"
                        :class="{ 'is-unavailable': !block.available }"
                    >
                        <div class="dashboard-page-editor__item-toolbar">
                            <label class="dashboard-page-editor__visibility">
                                <input v-model="block.visible" type="checkbox" :disabled="!block.available" />
                                <span>{{ !block.available ? 'Sem ZT neste evento' : (block.visible ? 'Visível' : 'Oculto') }}</span>
                            </label>
                            <div class="dashboard-page-editor__order">
                                <button
                                    type="button"
                                    aria-label="Mover para cima"
                                    :disabled="isFirst(draft.blocks, block.key, { field: 'area', value: group.key })"
                                    @click="moveItem(draft.blocks, block.key, -1, { field: 'area', value: group.key })"
                                >↑</button>
                                <button
                                    type="button"
                                    aria-label="Mover para baixo"
                                    :disabled="isLast(draft.blocks, block.key, { field: 'area', value: group.key })"
                                    @click="moveItem(draft.blocks, block.key, 1, { field: 'area', value: group.key })"
                                >↓</button>
                            </div>
                        </div>
                        <label>Título<input v-model.trim="block.label" maxlength="80" /></label>
                        <label>Categoria<input v-model.trim="block.helper" maxlength="120" /></label>
                    </article>
                </template>
            </section>

            <section v-else class="dashboard-page-editor__list">
                <div class="dashboard-page-editor__intro">
                    <strong>Cards e indicadores</strong>
                    <span>Personalize os nomes, a visibilidade e a ordem dentro de cada bloco.</span>
                </div>

                <template v-for="group in metricGroups" :key="group.key">
                    <h3>{{ group.label }}</h3>
                    <article
                        v-for="metric in itemsForGroup(group.key)"
                        :key="metric.key"
                        class="dashboard-page-editor__item"
                        :class="{ 'is-unavailable': !metric.available }"
                    >
                        <div class="dashboard-page-editor__item-toolbar">
                            <label class="dashboard-page-editor__visibility">
                                <input v-model="metric.visible" type="checkbox" :disabled="!metric.available" />
                                <span>{{ !metric.available ? 'Sem ZT neste evento' : (metric.visible ? 'Visível' : 'Oculto') }}</span>
                            </label>
                            <div class="dashboard-page-editor__order">
                                <button
                                    type="button"
                                    aria-label="Mover para cima"
                                    :disabled="isFirst(draft.metrics, metric.key, { field: 'group', value: group.key })"
                                    @click="moveItem(draft.metrics, metric.key, -1, { field: 'group', value: group.key })"
                                >↑</button>
                                <button
                                    type="button"
                                    aria-label="Mover para baixo"
                                    :disabled="isLast(draft.metrics, metric.key, { field: 'group', value: group.key })"
                                    @click="moveItem(draft.metrics, metric.key, 1, { field: 'group', value: group.key })"
                                >↓</button>
                            </div>
                        </div>
                        <label>Label<input v-model.trim="metric.label" maxlength="80" /></label>
                        <label v-if="metric.helper">Texto auxiliar<input v-model.trim="metric.helper" maxlength="120" /></label>
                    </article>
                </template>
            </section>
        </div>

        <footer class="dashboard-page-editor__footer">
            <button type="button" class="is-secondary" :disabled="props.saving" @click="emit('reset')">Restaurar padrão</button>
            <button type="button" class="is-secondary" :disabled="props.saving" @click="emit('close')">Cancelar</button>
            <button type="button" class="is-primary" :disabled="props.saving" @click="emit('save', cloneConfiguration(draft))">
                {{ props.saving ? 'A publicar...' : 'Publicar alterações' }}
            </button>
        </footer>
    </aside>
</template>

<style scoped>
.dashboard-page-editor {
    position: fixed;
    z-index: 80;
    top: 1rem;
    right: 1rem;
    bottom: 1rem;
    display: flex;
    width: min(28rem, calc(100vw - 2rem));
    flex-direction: column;
    overflow: hidden;
    border: 1px solid color-mix(in srgb, var(--accent) 35%, var(--card-border));
    border-radius: 1.5rem;
    color: var(--text-main);
    background: color-mix(in srgb, var(--card-bg) 97%, var(--accent) 3%);
    box-shadow: 0 32px 80px -28px rgba(1, 13, 30, 0.7);
}

.dashboard-page-editor.is-embedded {
    position: relative;
    inset: auto;
    width: 100%;
    min-height: 38rem;
    overflow: visible;
    box-shadow: 0 24px 60px -42px color-mix(in srgb, var(--accent-strong) 72%, transparent);
}

.dashboard-page-editor__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    border-bottom: 1px solid var(--line-color);
    padding: 1.25rem;
}

.dashboard-page-editor__header span,
.dashboard-page-editor__header h2,
.dashboard-page-editor__header p {
    display: block;
    margin: 0;
}

.dashboard-page-editor__header span {
    color: var(--accent-strong);
    font-size: 0.65rem;
    font-weight: 800;
    letter-spacing: 0.15em;
    text-transform: uppercase;
}

.dashboard-page-editor__header h2 {
    margin-top: 0.25rem;
    font-size: 1.35rem;
    font-weight: 850;
}

.dashboard-page-editor__header p,
.dashboard-page-editor__preset p,
.dashboard-page-editor__intro span {
    margin-top: 0.3rem;
    color: var(--text-muted);
    font-size: 0.76rem;
    line-height: 1.45;
}

.dashboard-page-editor__header > button {
    display: inline-flex;
    width: 2.25rem;
    height: 2.25rem;
    flex: none;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    color: var(--text-muted);
    background: color-mix(in srgb, var(--line-color) 65%, transparent);
    font-size: 1.3rem;
}

.dashboard-page-editor__preset {
    border-bottom: 1px solid var(--line-color);
    padding: 1rem 1.25rem;
}

.dashboard-page-editor__preset > label,
.dashboard-page-editor__item > label {
    display: block;
    color: var(--text-muted);
    font-size: 0.68rem;
    font-weight: 750;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.dashboard-page-editor__preset > div {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.55rem;
    margin-top: 0.45rem;
}

.dashboard-page-editor select,
.dashboard-page-editor input[type='text'],
.dashboard-page-editor__item input:not([type='checkbox']) {
    width: 100%;
    border: 1px solid var(--card-border);
    border-radius: 0.75rem;
    padding: 0.65rem 0.75rem;
    color: var(--input-text);
    background: var(--input-bg);
    font-size: 0.82rem;
}

.dashboard-page-editor__preset button,
.dashboard-page-editor__footer button {
    border-radius: 0.75rem;
    padding: 0.65rem 0.9rem;
    font-size: 0.78rem;
    font-weight: 800;
}

.dashboard-page-editor__preset button,
.dashboard-page-editor__footer .is-primary {
    color: white;
    background: linear-gradient(135deg, var(--accent), var(--accent-strong));
}

.dashboard-page-editor__tabs {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.25rem;
    border-bottom: 1px solid var(--line-color);
    padding: 0.65rem 1.25rem;
}

.dashboard-page-editor__tabs button {
    border-radius: 0.65rem;
    padding: 0.55rem 0.35rem;
    color: var(--text-muted);
    font-size: 0.74rem;
    font-weight: 800;
}

.dashboard-page-editor__tabs button.is-active {
    color: white;
    background: var(--accent-strong);
}

.dashboard-page-editor__body {
    flex: 1;
    overflow-y: auto;
    padding: 1rem 1.25rem 6rem;
}

.dashboard-page-editor.is-embedded .dashboard-page-editor__body {
    overflow: visible;
    padding-bottom: 1.25rem;
}

.dashboard-page-editor__list {
    display: grid;
    gap: 0.75rem;
}

.dashboard-page-editor__intro {
    margin-bottom: 0.25rem;
}

.dashboard-page-editor__intro strong {
    display: block;
    font-size: 0.9rem;
}

.dashboard-page-editor__list > h3 {
    margin: 0.8rem 0 0;
    color: var(--accent-strong);
    font-size: 0.67rem;
    font-weight: 850;
    letter-spacing: 0.14em;
    text-transform: uppercase;
}

.dashboard-page-editor__item {
    display: grid;
    gap: 0.65rem;
    border: 1px solid var(--card-border);
    border-radius: 1rem;
    padding: 0.85rem;
    background: color-mix(in srgb, var(--card-bg) 88%, var(--accent) 12%);
}

.dashboard-page-editor__item.is-unavailable {
    opacity: 0.58;
}

.dashboard-page-editor__item label input:not([type='checkbox']) {
    display: block;
    margin-top: 0.35rem;
    letter-spacing: normal;
    text-transform: none;
}

.dashboard-page-editor__item-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.dashboard-page-editor__visibility {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--text-soft);
    font-size: 0.72rem;
    font-weight: 750;
}

.dashboard-page-editor__visibility input {
    width: 1rem;
    height: 1rem;
    accent-color: var(--accent-strong);
}

.dashboard-page-editor__order {
    display: flex;
    gap: 0.3rem;
}

.dashboard-page-editor__order button {
    display: inline-flex;
    width: 1.9rem;
    height: 1.9rem;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--card-border);
    border-radius: 0.55rem;
    color: var(--text-main);
    background: var(--card-bg);
    font-weight: 900;
}

.dashboard-page-editor button:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}

.dashboard-page-editor__footer {
    display: grid;
    grid-template-columns: auto auto minmax(0, 1fr);
    gap: 0.5rem;
    border-top: 1px solid var(--line-color);
    padding: 0.9rem 1.25rem;
    background: color-mix(in srgb, var(--card-bg) 96%, transparent);
}

.dashboard-page-editor__footer .is-secondary {
    border: 1px solid var(--card-border);
    color: var(--text-main);
    background: var(--card-bg);
}

@media (min-width: 1024px) {
    .dashboard-page-editor.is-embedded .dashboard-page-editor__list {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .dashboard-page-editor.is-embedded .dashboard-page-editor__intro,
    .dashboard-page-editor.is-embedded .dashboard-page-editor__list > h3 {
        grid-column: 1 / -1;
    }
}

@media (max-width: 640px) {
    .dashboard-page-editor {
        top: 0.5rem;
        right: 0.5rem;
        bottom: 0.5rem;
        width: calc(100vw - 1rem);
    }

    .dashboard-page-editor__footer {
        grid-template-columns: 1fr 1fr;
    }

    .dashboard-page-editor__footer .is-primary {
        grid-column: 1 / -1;
    }
}
</style>
