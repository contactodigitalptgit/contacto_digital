<script setup lang="ts">
import Modal from '@/Components/Modal.vue';
import { confirmAction, showErrorToast, showSuccessToast } from '@/lib/swal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

interface ClientItem {
    id: number;
    name: string;
    business_name: string | null;
    address: string;
    phone: string;
    email: string;
    events_count: number;
    is_active: boolean;
}

defineProps<{
    clients: ClientItem[];
}>();

const showCreateClientModal = ref(false);
const showEditClientModal = ref(false);
const editingClientId = ref<number | null>(null);

const createClientForm = useForm({
    name: '',
    business_name: '',
    address: '',
    phone: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const editClientForm = useForm({
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

const openEditClientModal = (client: ClientItem) => {
    editingClientId.value = client.id;
    editClientForm.name = client.name;
    editClientForm.business_name = client.business_name ?? '';
    editClientForm.address = client.address;
    editClientForm.phone = client.phone;
    editClientForm.email = client.email;
    editClientForm.password = '';
    editClientForm.password_confirmation = '';
    editClientForm.clearErrors();
    showEditClientModal.value = true;
};

const closeEditClientModal = () => {
    showEditClientModal.value = false;
    editingClientId.value = null;
    editClientForm.clearErrors();
};

const submitEditClient = () => {
    if (!editingClientId.value) {
        return;
    }

    editClientForm.put(route('admin.clients.update', editingClientId.value), {
        preserveScroll: true,
        onSuccess: () => {
            showEditClientModal.value = false;
            editingClientId.value = null;
            editClientForm.reset();
        },
    });
};

const toggleClientStatus = async (client: ClientItem) => {
    const nextStatus = !client.is_active;
    const confirmed = await confirmAction({
        title: nextStatus ? 'Ativar cliente?' : 'Desativar cliente?',
        text: nextStatus
            ? 'O cliente voltará a acessar o sistema.'
            : 'O cliente não conseguirá mais fazer login.',
        confirmButtonText: nextStatus ? 'Ativar' : 'Desativar',
    });

    if (!confirmed) {
        return;
    }

    router.patch(
        route('admin.clients.toggle-status', client.id),
        {
            is_active: nextStatus,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                void showSuccessToast(
                    nextStatus
                        ? 'Cliente ativado com sucesso.'
                        : 'Cliente desativado com sucesso.',
                );
            },
            onError: () => {
                void showErrorToast(
                    'Não foi possível atualizar o status do cliente.',
                );
            },
        },
    );
};

const deleteClient = async (client: ClientItem) => {
    const confirmed = await confirmAction({
        title: 'Deletar cliente?',
        text: `Esta ação remove ${client.name} e todos os eventos vinculados. Não pode ser desfeita.`,
        confirmButtonText: 'Deletar',
    });

    if (!confirmed) {
        return;
    }

    router.delete(route('admin.clients.destroy', client.id), {
        preserveScroll: true,
        onSuccess: () => {
            void showSuccessToast('Cliente deletado com sucesso.');
        },
        onError: () => {
            void showErrorToast('Não foi possível deletar o cliente.');
        },
    });
};
</script>

<template>
    <Head title="Clientes" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="dash-page-title">
                    Clientes
                </h2>

                <button
                    type="button"
                    @click="openCreateClientModal"
                    class="dash-action-button dash-action-button-inline w-full justify-center sm:w-auto"
                >
                    Novo cliente
                </button>
            </div>
        </template>

        <div class="dash-page">
            <section class="dash-card">
                <div class="admin-clients-mobile-list md:hidden">
                    <article
                        v-for="client in clients"
                        :key="client.id"
                        class="admin-clients-mobile-card"
                    >
                        <div class="admin-clients-mobile-top">
                            <div class="min-w-0">
                                <p class="admin-clients-name">{{ client.name }}</p>
                                <p class="admin-clients-sub">
                                    {{ client.business_name || 'Sem nome comercial' }}
                                </p>
                            </div>
                            <span
                                class="status-pill shrink-0"
                                :class="client.is_active ? 'success' : 'neutral'"
                            >
                                {{ client.is_active ? 'Ativo' : 'Inativo' }}
                            </span>
                        </div>

                        <div class="admin-clients-mobile-grid">
                            <div class="admin-clients-mobile-item admin-clients-mobile-item-full">
                                <p class="admin-clients-mobile-label">Endereço</p>
                                <p class="admin-clients-mobile-value">{{ client.address }}</p>
                            </div>

                            <div class="admin-clients-mobile-item">
                                <p class="admin-clients-mobile-label">Telefone</p>
                                <p class="admin-clients-mobile-value">{{ client.phone }}</p>
                            </div>

                            <div class="admin-clients-mobile-item">
                                <p class="admin-clients-mobile-label">Eventos</p>
                                <p class="admin-clients-mobile-value">{{ client.events_count }}</p>
                            </div>

                            <div class="admin-clients-mobile-item admin-clients-mobile-item-full">
                                <p class="admin-clients-mobile-label">Usuário</p>
                                <p class="admin-clients-mobile-value">{{ client.email }}</p>
                            </div>
                        </div>

                        <div class="admin-clients-actions">
                            <Link
                                :href="route('admin.clients.dashboard', client.id)"
                                class="admin-client-icon-btn"
                                title="Ver dashboard do cliente"
                                aria-label="Ver dashboard do cliente"
                            >
                                <svg
                                    class="h-4 w-4"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="2"
                                >
                                    <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z" />
                                    <circle cx="12" cy="12" r="2.8" />
                                </svg>
                            </Link>

                            <button
                                type="button"
                                class="admin-client-icon-btn"
                                title="Editar cliente"
                                aria-label="Editar cliente"
                                @click="openEditClientModal(client)"
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
                                class="admin-client-icon-btn"
                                :class="{ warning: client.is_active, success: !client.is_active }"
                                :title="client.is_active ? 'Desativar cliente' : 'Ativar cliente'"
                                :aria-label="client.is_active ? 'Desativar cliente' : 'Ativar cliente'"
                                @click="toggleClientStatus(client)"
                            >
                                <svg
                                    v-if="client.is_active"
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
                                class="admin-client-icon-btn danger"
                                title="Deletar cliente"
                                aria-label="Deletar cliente"
                                @click="deleteClient(client)"
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

                    <div v-if="!clients.length" class="admin-clients-mobile-empty">
                        Nenhum cliente cadastrado.
                    </div>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="admin-clients-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Nome comercial</th>
                                <th>Contato</th>
                                <th>Eventos</th>
                                <th>Status</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="client in clients" :key="client.id">
                                <td>
                                    <p class="admin-clients-name">{{ client.name }}</p>
                                    <p class="admin-clients-sub">
                                        {{ client.address }}
                                    </p>
                                </td>
                                <td class="admin-clients-text">
                                    {{ client.business_name || 'Sem nome comercial' }}
                                </td>
                                <td class="admin-clients-text">
                                    <p>{{ client.phone }}</p>
                                    <p class="admin-clients-sub">
                                        {{ client.email }}
                                    </p>
                                </td>
                                <td class="admin-clients-text">
                                    {{ client.events_count }}
                                </td>
                                <td class="admin-clients-text">
                                    <span
                                        class="status-pill"
                                        :class="client.is_active ? 'success' : 'neutral'"
                                    >
                                        {{ client.is_active ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </td>
                                <td>
                                    <div class="admin-clients-actions">
                                        <Link
                                            :href="route('admin.clients.dashboard', client.id)"
                                            class="admin-client-icon-btn"
                                            title="Ver dashboard do cliente"
                                            aria-label="Ver dashboard do cliente"
                                        >
                                            <svg
                                                class="h-4 w-4"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="2"
                                            >
                                                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6Z" />
                                                <circle cx="12" cy="12" r="2.8" />
                                            </svg>
                                        </Link>

                                        <button
                                            type="button"
                                            class="admin-client-icon-btn"
                                            title="Editar cliente"
                                            aria-label="Editar cliente"
                                            @click="openEditClientModal(client)"
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
                                            class="admin-client-icon-btn"
                                            :class="{ warning: client.is_active, success: !client.is_active }"
                                            :title="client.is_active ? 'Desativar cliente' : 'Ativar cliente'"
                                            :aria-label="client.is_active ? 'Desativar cliente' : 'Ativar cliente'"
                                            @click="toggleClientStatus(client)"
                                        >
                                            <svg
                                                v-if="client.is_active"
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
                                            class="admin-client-icon-btn danger"
                                            title="Deletar cliente"
                                            aria-label="Deletar cliente"
                                            @click="deleteClient(client)"
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
                            <tr v-if="!clients.length">
                                <td
                                    colspan="6"
                                    class="py-8 text-center text-sm"
                                >
                                    <span class="dash-muted-text">
                                        Nenhum cliente cadastrado.
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
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
                        <label class="dash-modal-label" for="client_name_create">
                            Nome
                        </label>
                        <input
                            id="client_name_create"
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
                        <label class="dash-modal-label" for="client_business_name_create">
                            Nome comercial (opcional)
                        </label>
                        <input
                            id="client_business_name_create"
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
                        <label class="dash-modal-label" for="client_address_create">
                            Endereço
                        </label>
                        <input
                            id="client_address_create"
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
                        <label class="dash-modal-label" for="client_phone_create">
                            Telefone
                        </label>
                        <input
                            id="client_phone_create"
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
                        <label class="dash-modal-label" for="client_email_create">
                            Usuário (e-mail)
                        </label>
                        <input
                            id="client_email_create"
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
                        <label class="dash-modal-label" for="client_password_create">
                            Senha
                        </label>
                        <input
                            id="client_password_create"
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
                        <label class="dash-modal-label" for="client_password_confirmation_create">
                            Confirmar senha
                        </label>
                        <input
                            id="client_password_confirmation_create"
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

        <Modal
            :show="showEditClientModal"
            max-width="2xl"
            @close="closeEditClientModal"
        >
            <form class="dash-modal" @submit.prevent="submitEditClient">
                <div class="dash-modal-header">
                    <h3 class="dash-modal-title">Editar Cliente</h3>
                    <button
                        type="button"
                        class="dash-modal-close"
                        @click="closeEditClientModal"
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
                        <label class="dash-modal-label" for="client_name_edit">
                            Nome
                        </label>
                        <input
                            id="client_name_edit"
                            v-model="editClientForm.name"
                            class="dash-modal-input"
                            type="text"
                            required
                            autofocus
                        />
                        <p
                            v-if="editClientForm.errors.name"
                            class="dash-modal-error"
                        >
                            {{ editClientForm.errors.name }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="client_business_name_edit">
                            Nome comercial (opcional)
                        </label>
                        <input
                            id="client_business_name_edit"
                            v-model="editClientForm.business_name"
                            class="dash-modal-input"
                            type="text"
                        />
                        <p
                            v-if="editClientForm.errors.business_name"
                            class="dash-modal-error"
                        >
                            {{ editClientForm.errors.business_name }}
                        </p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="client_address_edit">
                            Endereço
                        </label>
                        <input
                            id="client_address_edit"
                            v-model="editClientForm.address"
                            class="dash-modal-input"
                            type="text"
                            required
                        />
                        <p
                            v-if="editClientForm.errors.address"
                            class="dash-modal-error"
                        >
                            {{ editClientForm.errors.address }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="client_phone_edit">
                            Telefone
                        </label>
                        <input
                            id="client_phone_edit"
                            v-model="editClientForm.phone"
                            class="dash-modal-input"
                            type="text"
                            required
                        />
                        <p
                            v-if="editClientForm.errors.phone"
                            class="dash-modal-error"
                        >
                            {{ editClientForm.errors.phone }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="client_email_edit">
                            Usuário (e-mail)
                        </label>
                        <input
                            id="client_email_edit"
                            v-model="editClientForm.email"
                            class="dash-modal-input"
                            type="email"
                            required
                        />
                        <p
                            v-if="editClientForm.errors.email"
                            class="dash-modal-error"
                        >
                            {{ editClientForm.errors.email }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="client_password_edit">
                            Nova senha (opcional)
                        </label>
                        <input
                            id="client_password_edit"
                            v-model="editClientForm.password"
                            class="dash-modal-input"
                            type="password"
                        />
                        <p
                            v-if="editClientForm.errors.password"
                            class="dash-modal-error"
                        >
                            {{ editClientForm.errors.password }}
                        </p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="client_password_confirmation_edit">
                            Confirmar nova senha
                        </label>
                        <input
                            id="client_password_confirmation_edit"
                            v-model="editClientForm.password_confirmation"
                            class="dash-modal-input"
                            type="password"
                        />
                    </div>
                </div>

                <div class="dash-modal-actions">
                    <button
                        type="button"
                        class="dash-modal-cancel"
                        @click="closeEditClientModal"
                    >
                        Cancelar
                    </button>

                    <button
                        type="submit"
                        class="dash-action-button dash-action-button-inline"
                        :disabled="editClientForm.processing"
                        :class="{ 'opacity-60': editClientForm.processing }"
                    >
                        Atualizar cliente
                    </button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
