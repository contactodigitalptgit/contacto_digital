<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import Modal from '@/Components/Modal.vue';
import { useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const confirmingUserDeletion = ref(false);
const passwordInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    password: '',
});

const confirmUserDeletion = () => {
    confirmingUserDeletion.value = true;

    nextTick(() => passwordInput.value?.focus());
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => {
            form.reset();
        },
    });
};

const closeModal = () => {
    confirmingUserDeletion.value = false;
    form.clearErrors();
    form.reset();
};
</script>

<template>
    <section class="space-y-6">
        <header>
            <h2 class="profile-section-title">
                Excluir conta
            </h2>

            <p class="profile-section-text">
                Esta ação remove permanentemente sua conta e os dados associados.
                Antes de continuar, garanta que você já salvou o que precisa.
            </p>
        </header>

        <button type="button" class="profile-danger-btn" @click="confirmUserDeletion">
            Excluir conta
        </button>

        <Modal :show="confirmingUserDeletion" @close="closeModal">
            <div class="dash-modal">
                <h2 class="profile-section-title">
                    Confirmar exclusão da conta
                </h2>

                <p class="profile-modal-text">
                    Para confirmar a exclusão permanente, informe sua senha.
                </p>

                <div class="mt-6">
                    <label for="password" class="sr-only">Senha</label>

                    <input
                        id="password"
                        ref="passwordInput"
                        v-model="form.password"
                        type="password"
                        class="dash-modal-input w-full sm:w-3/4"
                        placeholder="Senha"
                        @keyup.enter="deleteUser"
                    />

                    <InputError :message="form.errors.password" class="mt-2" />
                </div>

                <div class="dash-modal-actions">
                    <button
                        type="button"
                        class="dash-modal-cancel"
                        @click="closeModal"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        class="profile-danger-btn"
                        :class="{ 'opacity-60': form.processing }"
                        :disabled="form.processing"
                        @click="deleteUser"
                    >
                        Excluir conta
                    </button>
                </div>
            </div>
        </Modal>
    </section>
</template>
