<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();

const user = usePage().props.auth.user;

const form = useForm({
    name: user.name,
    email: user.email,
});
</script>

<template>
    <section>
        <header>
            <h2 class="profile-section-title">
                Informações do perfil
            </h2>

            <p class="profile-section-text">
                Atualize os dados da sua conta e o endereço de e-mail.
            </p>
        </header>

        <form
            class="mt-6 space-y-4"
            @submit.prevent="form.patch(route('profile.update'))"
        >
            <div class="dash-modal-field">
                <label class="dash-modal-label" for="profile_name">
                    Nome
                </label>

                <input
                    id="profile_name"
                    v-model="form.name"
                    class="dash-modal-input"
                    type="text"
                    required
                    autofocus
                    autocomplete="name"
                />

                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div class="dash-modal-field">
                <label class="dash-modal-label" for="profile_email">
                    E-mail
                </label>

                <input
                    id="profile_email"
                    v-model="form.email"
                    class="dash-modal-input"
                    type="email"
                    required
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div v-if="mustVerifyEmail && user.email_verified_at === null">
                <p class="profile-section-text">
                    Seu e-mail ainda não foi verificado.
                    <Link
                        :href="route('verification.send')"
                        method="post"
                        as="button"
                        class="profile-inline-link"
                    >
                        Clique aqui para reenviar o e-mail de verificação.
                    </Link>
                </p>

                <div
                    v-show="status === 'verification-link-sent'"
                    class="profile-success-text"
                >
                    Novo link de verificação enviado para seu e-mail.
                </div>
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
