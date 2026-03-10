<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const passwordInput = ref<HTMLInputElement | null>(null);
const currentPasswordInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value?.focus();
            }

            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value?.focus();
            }
        },
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="profile-section-title">
                Atualizar senha
            </h2>

            <p class="profile-section-text">
                Use uma senha forte e exclusiva para proteger seu acesso.
            </p>
        </header>

        <form class="mt-6 space-y-4" @submit.prevent="updatePassword">
            <div class="dash-modal-field">
                <label class="dash-modal-label" for="current_password">
                    Senha atual
                </label>

                <input
                    id="current_password"
                    ref="currentPasswordInput"
                    v-model="form.current_password"
                    class="dash-modal-input"
                    type="password"
                    autocomplete="current-password"
                />

                <InputError
                    :message="form.errors.current_password"
                    class="mt-2"
                />
            </div>

            <div class="dash-modal-field">
                <label class="dash-modal-label" for="password">
                    Nova senha
                </label>

                <input
                    id="password"
                    ref="passwordInput"
                    v-model="form.password"
                    class="dash-modal-input"
                    type="password"
                    autocomplete="new-password"
                />

                <InputError :message="form.errors.password" class="mt-2" />
            </div>

            <div class="dash-modal-field">
                <label class="dash-modal-label" for="password_confirmation">
                    Confirmar senha
                </label>

                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    class="dash-modal-input"
                    type="password"
                    autocomplete="new-password"
                />

                <InputError
                    :message="form.errors.password_confirmation"
                    class="mt-2"
                />
            </div>

            <div class="flex items-center gap-4">
                <button
                    type="submit"
                    class="dash-action-button dash-action-button-inline"
                    :disabled="form.processing"
                    :class="{ 'opacity-60': form.processing }"
                >
                    Salvar
                </button>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p
                        v-if="form.recentlySuccessful"
                        class="profile-section-text"
                    >
                        Salvo.
                    </p>
                </Transition>
            </div>
        </form>
    </section>
</template>
