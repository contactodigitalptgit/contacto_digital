<script setup lang="ts">
import { computed, ref, watch } from 'vue';

interface CalendarDay {
    iso: string;
    day: number;
    inMonth: boolean;
    isToday: boolean;
    isEventDay: boolean;
    isInSelection: boolean;
    isRangeStart: boolean;
    isRangeEnd: boolean;
}

const props = withDefaults(defineProps<{
    dateFrom: string;
    dateTo: string;
    eventStart?: string | null;
    eventEnd?: string | null;
    idPrefix?: string;
}>(), {
    eventStart: null,
    eventEnd: null,
    idPrefix: 'event-date-range',
});

const emit = defineEmits<{
    'update:dateFrom': [value: string];
    'update:dateTo': [value: string];
}>();

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

function toIsoDate(date: Date): string {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function normalizeDate(value?: string | null): string {
    if (!value) {
        return '';
    }

    const plainDate = value.match(/^(\d{4}-\d{2}-\d{2})/)?.[1];

    return plainDate ?? '';
}

function parseDate(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day, 12);
}

const today = toIsoDate(new Date());
const eventStartDate = computed(() => normalizeDate(props.eventStart));
const eventEndDate = computed(() => normalizeDate(props.eventEnd) || eventStartDate.value);
const initialVisibleDate = props.dateFrom || eventStartDate.value || today;
const visibleMonth = ref(new Date(
    parseDate(initialVisibleDate).getFullYear(),
    parseDate(initialVisibleDate).getMonth(),
    1,
    12,
));
const selectingEnd = ref(false);

const monthLabel = computed(() => new Intl.DateTimeFormat('pt-PT', {
    month: 'long',
    year: 'numeric',
}).format(visibleMonth.value));

const eventPeriodLabel = computed(() => {
    if (!eventStartDate.value) {
        return '';
    }

    const format = (value: string) => new Intl.DateTimeFormat('pt-PT', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(parseDate(value));

    return eventEndDate.value && eventEndDate.value !== eventStartDate.value
        ? `${format(eventStartDate.value)} – ${format(eventEndDate.value)}`
        : format(eventStartDate.value);
});

const calendarDays = computed<CalendarDay[]>(() => {
    const year = visibleMonth.value.getFullYear();
    const month = visibleMonth.value.getMonth();
    const first = new Date(year, month, 1, 12);
    const mondayOffset = (first.getDay() + 6) % 7;
    const gridStart = new Date(year, month, 1 - mondayOffset, 12);

    return Array.from({ length: 42 }, (_, index) => {
        const date = new Date(
            gridStart.getFullYear(),
            gridStart.getMonth(),
            gridStart.getDate() + index,
            12,
        );
        const iso = toIsoDate(date);

        return {
            iso,
            day: date.getDate(),
            inMonth: date.getMonth() === month,
            isToday: iso === today,
            isEventDay: eventStartDate.value !== ''
                && iso >= eventStartDate.value
                && iso <= eventEndDate.value,
            isInSelection: props.dateFrom !== ''
                && props.dateTo !== ''
                && iso >= props.dateFrom
                && iso <= props.dateTo,
            isRangeStart: iso === props.dateFrom,
            isRangeEnd: iso === props.dateTo,
        };
    });
});

watch(() => props.dateFrom, (value) => {
    if (!value) {
        return;
    }

    const date = parseDate(value);

    if (date.getMonth() !== visibleMonth.value.getMonth()
        || date.getFullYear() !== visibleMonth.value.getFullYear()) {
        visibleMonth.value = new Date(date.getFullYear(), date.getMonth(), 1, 12);
    }
});

function changeMonth(offset: number): void {
    visibleMonth.value = new Date(
        visibleMonth.value.getFullYear(),
        visibleMonth.value.getMonth() + offset,
        1,
        12,
    );
}

function showEventMonth(): void {
    if (!eventStartDate.value) {
        return;
    }

    const date = parseDate(eventStartDate.value);
    visibleMonth.value = new Date(date.getFullYear(), date.getMonth(), 1, 12);
}

function selectDay(iso: string): void {
    if (!selectingEnd.value || props.dateFrom === '') {
        emit('update:dateFrom', iso);
        emit('update:dateTo', iso);
        selectingEnd.value = true;

        return;
    }

    if (iso < props.dateFrom) {
        emit('update:dateFrom', iso);
    } else {
        emit('update:dateTo', iso);
    }

    selectingEnd.value = false;
}

function onDateFromInput(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    emit('update:dateFrom', value);

    if (value !== '' && props.dateTo !== '' && props.dateTo < value) {
        emit('update:dateTo', value);
    }
}

function onDateToInput(event: Event): void {
    emit('update:dateTo', (event.target as HTMLInputElement).value);
}
</script>

<template>
    <div class="event-date-range-picker">
        <div class="event-date-range-fields">
            <label :for="`${idPrefix}-from`">
                <span>Data inicial</span>
                <input
                    :id="`${idPrefix}-from`"
                    :value="dateFrom"
                    type="date"
                    class="dash-input"
                    :max="dateTo || undefined"
                    @input="onDateFromInput"
                />
            </label>
            <label :for="`${idPrefix}-to`">
                <span>Data final</span>
                <input
                    :id="`${idPrefix}-to`"
                    :value="dateTo"
                    type="date"
                    class="dash-input"
                    :min="dateFrom || undefined"
                    @input="onDateToInput"
                />
            </label>
        </div>

        <div class="event-date-calendar">
            <div class="event-date-calendar-header">
                <button type="button" aria-label="Mês anterior" @click="changeMonth(-1)">‹</button>
                <strong>{{ monthLabel }}</strong>
                <button type="button" aria-label="Mês seguinte" @click="changeMonth(1)">›</button>
            </div>

            <div class="event-date-calendar-weekdays" aria-hidden="true">
                <span>Seg</span>
                <span>Ter</span>
                <span>Qua</span>
                <span>Qui</span>
                <span>Sex</span>
                <span>Sáb</span>
                <span>Dom</span>
            </div>

            <div class="event-date-calendar-grid" role="grid" :aria-label="`Calendário de ${monthLabel}`">
                <button
                    v-for="day in calendarDays"
                    :key="day.iso"
                    type="button"
                    :class="{
                        'is-outside-month': !day.inMonth,
                        'is-today': day.isToday,
                        'is-event-day': day.isEventDay,
                        'is-in-selection': day.isInSelection,
                        'is-range-start': day.isRangeStart,
                        'is-range-end': day.isRangeEnd,
                    }"
                    :aria-label="day.iso"
                    :aria-pressed="day.isRangeStart || day.isRangeEnd"
                    @click="selectDay(day.iso)"
                >
                    <span>{{ day.day }}</span>
                    <i v-if="day.isEventDay" aria-hidden="true" />
                </button>
            </div>

            <div class="event-date-calendar-legend">
                <button v-if="eventPeriodLabel" type="button" @click="showEventMonth">
                    <i /> Dias do evento: {{ eventPeriodLabel }}
                </button>
                <small>{{ selectingEnd ? 'Agora selecione a data final.' : 'Selecione a data inicial.' }}</small>
            </div>
        </div>
    </div>
</template>

<style scoped>
.event-date-range-picker {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 0.85rem;
}

.event-date-range-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.65rem;
}

.event-date-range-fields label {
    display: flex;
    min-width: 0;
    flex-direction: column;
    gap: 0.35rem;
}

.event-date-range-fields label > span {
    color: #aebdca;
    font-size: 0.6875rem;
    font-weight: 600;
}

.event-date-range-fields .dash-input {
    width: 100%;
    min-width: 0;
    font-size: 0.75rem;
}

.event-date-calendar {
    border: 1px solid rgba(237, 237, 237, 0.1);
    border-radius: 0.75rem;
    padding: 0.7rem;
    background: rgba(3, 24, 43, 0.55);
}

.event-date-calendar-header {
    display: grid;
    grid-template-columns: 2rem 1fr 2rem;
    align-items: center;
    margin-bottom: 0.55rem;
}

.event-date-calendar-header strong {
    color: #ededed;
    font-size: 0.78rem;
    text-align: center;
    text-transform: capitalize;
}

.event-date-calendar-header button {
    width: 2rem;
    height: 2rem;
    border: 1px solid rgba(237, 237, 237, 0.1);
    border-radius: 0.45rem;
    color: #c6d1dc;
    background: #082742;
    font-size: 1.25rem;
    line-height: 1;
}

.event-date-calendar-header button:hover,
.event-date-calendar-header button:focus-visible {
    border-color: rgba(231, 255, 73, 0.5);
    outline: none;
}

.event-date-calendar-weekdays,
.event-date-calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
}

.event-date-calendar-weekdays span {
    padding-block: 0.25rem;
    color: #71879b;
    font-size: 0.55rem;
    font-weight: 700;
    text-align: center;
    text-transform: uppercase;
}

.event-date-calendar-grid button {
    position: relative;
    display: inline-flex;
    aspect-ratio: 1;
    min-width: 0;
    align-items: center;
    justify-content: center;
    border: 0;
    border-radius: 0.45rem;
    color: #c6d1dc;
    background: transparent;
    font-size: 0.68rem;
    cursor: pointer;
}

.event-date-calendar-grid button:hover,
.event-date-calendar-grid button:focus-visible {
    background: #103b60;
    outline: 1px solid rgba(231, 255, 73, 0.45);
}

.event-date-calendar-grid button.is-outside-month {
    color: #50667a;
}

.event-date-calendar-grid button.is-event-day {
    color: #edf6cf;
    background: rgba(231, 255, 73, 0.08);
}

.event-date-calendar-grid button.is-in-selection {
    border-radius: 0;
    color: #fff;
    background: rgba(45, 123, 255, 0.28);
}

.event-date-calendar-grid button.is-range-start,
.event-date-calendar-grid button.is-range-end {
    border-radius: 0.45rem;
    color: #03182b;
    background: #e7ff49;
    font-weight: 800;
}

.event-date-calendar-grid button.is-today:not(.is-range-start):not(.is-range-end) {
    box-shadow: inset 0 0 0 1px #55a8ff;
}

.event-date-calendar-grid button i {
    position: absolute;
    bottom: 0.18rem;
    width: 0.22rem;
    height: 0.22rem;
    border-radius: 999px;
    background: #e7ff49;
}

.event-date-calendar-grid button.is-range-start i,
.event-date-calendar-grid button.is-range-end i {
    background: #03182b;
}

.event-date-calendar-legend {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.65rem;
    margin-top: 0.6rem;
    color: #91a6ba;
    font-size: 0.6rem;
}

.event-date-calendar-legend button {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    border: 0;
    padding: 0;
    color: #91a6ba;
    background: transparent;
    font: inherit;
    text-align: left;
    cursor: pointer;
}

.event-date-calendar-legend button:hover,
.event-date-calendar-legend button:focus-visible {
    color: #e7ff49;
    outline: none;
}

.event-date-calendar-legend button i {
    width: 0.42rem;
    height: 0.42rem;
    flex: none;
    border-radius: 999px;
    background: #e7ff49;
}

.event-date-calendar-legend small {
    color: #71879b;
    text-align: right;
}

@media (max-width: 520px) {
    .event-date-range-fields {
        grid-template-columns: 1fr;
    }

    .event-date-calendar-legend {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
