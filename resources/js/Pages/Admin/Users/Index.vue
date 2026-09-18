<script setup lang="ts">
import Modal from '@/Components/Modal.vue';
import { confirmAction, showErrorToast, showSuccessToast } from '@/lib/swal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import type { PageProps } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

interface AdminItem {
    id: number;
    name: string;
    email: string;
    created_at: string;
}

defineProps<{
    admins: AdminItem[];
}>();

const page = usePage<PageProps>();
const currentUserId = page.props.auth.user.id;

const showCreateAdminModal = ref(false);
const showEditAdminModal = ref(false);
const editingAdminId = ref<number | null>(null);

const createAdminForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const editAdminForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const openCreateAdminModal = () => {
    showCreateAdminModal.value = true;
};

const closeCreateAdminModal = () => {
    showCreateAdminModal.value = false;
    createAdminForm.reset();
    createAdminForm.clearErrors();
};

const submitCreateAdmin = () => {
    createAdminForm.post(route('admin.users.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCreateAdminModal.value = false;
            createAdminForm.reset();
            void showSuccessToast('Administrador criado com sucesso.');
        },
        onError: () => void showErrorToast('Não foi possível criar o administrador.'),
    });
};

const openEditAdminModal = (admin: AdminItem) => {
    editingAdminId.value = admin.id;
    editAdminForm.name = admin.name;
    editAdminForm.email = admin.email;
    editAdminForm.password = '';
    editAdminForm.password_confirmation = '';
    editAdminForm.clearErrors();
    showEditAdminModal.value = true;
};

const closeEditAdminModal = () => {
    showEditAdminModal.value = false;
    editingAdminId.value = null;
    editAdminForm.clearErrors();
};

const submitEditAdmin = () => {
    if (!editingAdminId.value) {
        return;
    }

    editAdminForm.put(route('admin.users.update', editingAdminId.value), {
        preserveScroll: true,
        onSuccess: () => {
            showEditAdminModal.value = false;
            editingAdminId.value = null;
            editAdminForm.reset();
            void showSuccessToast('Administrador atualizado com sucesso.');
        },
        onError: () => void showErrorToast('Não foi possível atualizar o administrador.'),
    });
};

const deleteAdmin = async (admin: AdminItem) => {
    const confirmed = await confirmAction({
        title: 'Eliminar administrador?',
        text: `${admin.name} perderá o acesso à plataforma. Esta ação não pode ser desfeita.`,
        confirmButtonText: 'Eliminar',
    });

    if (!confirmed) {
        return;
    }

    router.delete(route('admin.users.destroy', admin.id), {
        preserveScroll: true,
        onSuccess: () => void showSuccessToast('Administrador eliminado.'),
        onError: (errors) => void showErrorToast(errors.id ?? 'Não foi possível eliminar o administrador.'),
    });
};

const formatDate = (date: string) => new Intl.DateTimeFormat('pt-PT', {
    dateStyle: 'medium',
}).format(new Date(date));
</script>

<template>
    <Head title="Administradores" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="dash-page-title">Administradores</h2>
                    <p class="dash-muted-text">Contas com acesso total à plataforma</p>
                </div>

                <button
                    type="button"
                    class="dash-action-button dash-action-button-inline w-full justify-center sm:w-auto"
                    @click="openCreateAdminModal"
                >
                    Novo administrador
                </button>
            </div>
        </template>

        <div class="dash-page">
            <section class="dash-card">
                <div class="admin-clients-mobile-list md:hidden">
                    <article
                        v-for="admin in admins"
                        :key="admin.id"
                        class="admin-clients-mobile-card"
                    >
                        <div class="admin-clients-mobile-top">
                            <div class="min-w-0">
                                <p class="admin-clients-name">{{ admin.name }}</p>
                                <p class="admin-clients-sub">{{ admin.email }}</p>
                            </div>
                            <span v-if="admin.id === currentUserId" class="status-pill neutral shrink-0">Você</span>
                        </div>

                        <div class="admin-clients-mobile-grid">
                            <div class="admin-clients-mobile-item admin-clients-mobile-item-full">
                                <p class="admin-clients-mobile-label">Criado em</p>
                                <p class="admin-clients-mobile-value">{{ formatDate(admin.created_at) }}</p>
                            </div>
                        </div>

                        <div class="admin-clients-actions">
                            <button
                                type="button"
                                class="admin-client-icon-btn"
                                title="Editar administrador"
                                aria-label="Editar administrador"
                                @click="openEditAdminModal(admin)"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 20h9" />
                                    <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
                                </svg>
                            </button>

                            <button
                                v-if="admin.id !== currentUserId"
                                type="button"
                                class="admin-client-icon-btn danger"
                                title="Eliminar administrador"
                                aria-label="Eliminar administrador"
                                @click="deleteAdmin(admin)"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M3 6h18" />
                                    <path d="M8 6V4h8v2" />
                                    <path d="M19 6l-1 14H6L5 6" />
                                    <path d="M10 11v6M14 11v6" />
                                </svg>
                            </button>
                        </div>
                    </article>

                    <div v-if="!admins.length" class="admin-clients-mobile-empty">
                        Nenhum administrador cadastrado.
                    </div>
                </div>

                <div class="hidden overflow-x-auto md:block">
                    <table class="admin-clients-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>Email</th>
                                <th>Criado em</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="admin in admins" :key="admin.id">
                                <td>
                                    <p class="admin-clients-name">{{ admin.name }}</p>
                                    <p v-if="admin.id === currentUserId" class="admin-clients-sub">Você</p>
                                </td>
                                <td class="admin-clients-text">{{ admin.email }}</td>
                                <td class="admin-clients-text">{{ formatDate(admin.created_at) }}</td>
                                <td>
                                    <div class="admin-clients-actions">
                                        <button
                                            type="button"
                                            class="admin-client-icon-btn"
                                            title="Editar administrador"
                                            aria-label="Editar administrador"
                                            @click="openEditAdminModal(admin)"
                                        >
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M12 20h9" />
                                                <path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" />
                                            </svg>
                                        </button>

                                        <button
                                            v-if="admin.id !== currentUserId"
                                            type="button"
                                            class="admin-client-icon-btn danger"
                                            title="Eliminar administrador"
                                            aria-label="Eliminar administrador"
                                            @click="deleteAdmin(admin)"
                                        >
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M3 6h18" />
                                                <path d="M8 6V4h8v2" />
                                                <path d="M19 6l-1 14H6L5 6" />
                                                <path d="M10 11v6M14 11v6" />
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!admins.length">
                                <td colspan="4" class="py-8 text-center text-sm">
                                    <span class="dash-muted-text">Nenhum administrador cadastrado.</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <Modal :show="showCreateAdminModal" max-width="md" @close="closeCreateAdminModal">
            <form class="dash-modal" @submit.prevent="submitCreateAdmin">
                <div class="dash-modal-header">
                    <h3 class="dash-modal-title">Novo administrador</h3>
                    <button type="button" class="dash-modal-close" @click="closeCreateAdminModal">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6L6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="dash-modal-grid">
                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="admin_name_create">Nome</label>
                        <input id="admin_name_create" v-model="createAdminForm.name" class="dash-modal-input" type="text" required autofocus />
                        <p v-if="createAdminForm.errors.name" class="dash-modal-error">{{ createAdminForm.errors.name }}</p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="admin_email_create">Email</label>
                        <input id="admin_email_create" v-model="createAdminForm.email" class="dash-modal-input" type="email" required />
                        <p v-if="createAdminForm.errors.email" class="dash-modal-error">{{ createAdminForm.errors.email }}</p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="admin_password_create">Senha</label>
                        <input id="admin_password_create" v-model="createAdminForm.password" class="dash-modal-input" type="password" required />
                        <p v-if="createAdminForm.errors.password" class="dash-modal-error">{{ createAdminForm.errors.password }}</p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="admin_password_confirmation_create">Confirmar senha</label>
                        <input
                            id="admin_password_confirmation_create"
                            v-model="createAdminForm.password_confirmation"
                            class="dash-modal-input"
                            type="password"
                            required
                        />
                    </div>

                    <div class="dash-modal-actions dash-modal-field-full">
                        <button class="dash-action-button dash-action-button-inline w-full justify-center sm:w-auto" :disabled="createAdminForm.processing">
                            Criar administrador
                        </button>
                    </div>
                </div>
            </form>
        </Modal>

        <Modal :show="showEditAdminModal" max-width="md" @close="closeEditAdminModal">
            <form class="dash-modal" @submit.prevent="submitEditAdmin">
                <div class="dash-modal-header">
                    <h3 class="dash-modal-title">Editar administrador</h3>
                    <button type="button" class="dash-modal-close" @click="closeEditAdminModal">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 6L6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="dash-modal-grid">
                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="admin_name_edit">Nome</label>
                        <input id="admin_name_edit" v-model="editAdminForm.name" class="dash-modal-input" type="text" required autofocus />
                        <p v-if="editAdminForm.errors.name" class="dash-modal-error">{{ editAdminForm.errors.name }}</p>
                    </div>

                    <div class="dash-modal-field dash-modal-field-full">
                        <label class="dash-modal-label" for="admin_email_edit">Email</label>
                        <input id="admin_email_edit" v-model="editAdminForm.email" class="dash-modal-input" type="email" required />
                        <p v-if="editAdminForm.errors.email" class="dash-modal-error">{{ editAdminForm.errors.email }}</p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="admin_password_edit">Nova senha (opcional)</label>
                        <input id="admin_password_edit" v-model="editAdminForm.password" class="dash-modal-input" type="password" placeholder="Deixe vazio para manter a senha atual" />
                        <p v-if="editAdminForm.errors.password" class="dash-modal-error">{{ editAdminForm.errors.password }}</p>
                    </div>

                    <div class="dash-modal-field">
                        <label class="dash-modal-label" for="admin_password_confirmation_edit">Confirmar nova senha</label>
                        <input
                            id="admin_password_confirmation_edit"
                            v-model="editAdminForm.password_confirmation"
                            class="dash-modal-input"
                            type="password"
                        />
                    </div>

                    <div class="dash-modal-actions dash-modal-field-full">
                        <button class="dash-action-button dash-action-button-inline w-full justify-center sm:w-auto" :disabled="editAdminForm.processing">
                            Guardar alterações
                        </button>
                    </div>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
