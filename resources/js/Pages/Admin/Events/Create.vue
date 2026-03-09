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

defineProps<{
    clients: ClientOption[];
}>();

const form = useForm({
    client_id: '' as number | '',
    title: '',
    description: '',
    event_date: '',
});

const submit = () => {
    form.post(route('admin.events.store'));
};
</script>

<template>
    <Head title="Novo evento" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Novo evento
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
                                <option disabled value="">
                                    Selecione um cliente
                                </option>
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

                        <div class="flex justify-end">
                            <PrimaryButton
                                :disabled="form.processing"
                                :class="{ 'opacity-25': form.processing }"
                            >
                                Salvar evento
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
