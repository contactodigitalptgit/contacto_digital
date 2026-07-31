<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

interface ClientOption {
    id: number;
    name: string;
    business_name: string | null;
}

interface EventData {
    id: number;
    client_id: number;
    title: string;
    description: string | null;
    event_date: string;
    report_starts_at: string | null;
    report_ends_at: string | null;
    show_zt_card: boolean;
}

const props = defineProps<{
    clients: ClientOption[];
    event: EventData;
}>();

const form = useForm({
    client_id: props.event.client_id,
    title: props.event.title,
    description: props.event.description ?? '',
    event_date: props.event.event_date,
    report_starts_at: props.event.report_starts_at ?? '',
    report_ends_at: props.event.report_ends_at ?? '',
    show_zt_card: props.event.show_zt_card,
});

const submit = () => {
    form.put(route('admin.events.update', props.event.id));
};
</script>

<template>
    <Head title="Editar evento" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Editar evento
            </h2>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <form class="space-y-6 p-6" @submit.prevent="submit">
                        <div>
                            <InputLabel for="client_id" value="Cliente" />
                            <select
                                id="client_id"
                                v-model="form.client_id"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                required
                            >
                                <option
                                    v-for="client in clients"
                                    :key="client.id"
                                    :value="client.id"
                                >
                                    {{ client.name }}
                                    {{
                                        client.business_name
                                            ? ` - ${client.business_name}`
                                            : ''
                                    }}
                                </option>
                            </select>
                            <InputError
                                class="mt-2"
                                :message="form.errors.client_id"
                            />
                        </div>

                        <div>
                            <InputLabel for="title" value="Título do evento" />
                            <TextInput
                                id="title"
                                v-model="form.title"
                                class="mt-1 block w-full"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.title" />
                        </div>

                        <div>
                            <InputLabel for="description" value="Descrição (opcional)" />
                            <textarea
                                id="description"
                                v-model="form.description"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                rows="4"
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.description"
                            />
                        </div>

                        <div>
                            <InputLabel for="event_date" value="Data do evento" />
                            <TextInput
                                id="event_date"
                                type="datetime-local"
                                v-model="form.event_date"
                                class="mt-1 block w-full"
                                required
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.event_date"
                            />
                        </div>

                        <div>
                            <InputLabel for="report_starts_at" value="Início do relatório (opcional)" />
                            <TextInput
                                id="report_starts_at"
                                type="datetime-local"
                                v-model="form.report_starts_at"
                                class="mt-1 block w-full"
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.report_starts_at"
                            />
                        </div>

                        <div>
                            <InputLabel for="report_ends_at" value="Fim do relatório" />
                            <TextInput
                                id="report_ends_at"
                                type="datetime-local"
                                v-model="form.report_ends_at"
                                class="mt-1 block w-full"
                                required
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.report_ends_at"
                            />
                        </div>

                        <section class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                            <div class="flex items-center gap-3">
                                <img
                                    src="/images/zicket-logo.png"
                                    alt="Zicket"
                                    class="h-12 w-12 rounded-xl object-contain"
                                />
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Integrações
                                    </p>
                                    <h3 class="text-sm font-semibold text-gray-900">
                                        Zicket / ZT - Card
                                    </h3>
                                </div>
                            </div>

                            <label class="mt-4 flex items-start gap-3 text-sm text-gray-700">
                                <input
                                    v-model="form.show_zt_card"
                                    type="checkbox"
                                    class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500"
                                />
                                <span>
                                    <strong class="block text-gray-900">Mostrar ZT - Card no dashboard</strong>
                                    Desligue para eventos sem carregamentos ZT.
                                </span>
                            </label>
                            <InputError
                                class="mt-2"
                                :message="form.errors.show_zt_card"
                            />
                        </section>

                        <div class="flex justify-end">
                            <PrimaryButton
                                :disabled="form.processing"
                                :class="{ 'opacity-25': form.processing }"
                            >
                                Atualizar evento
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
