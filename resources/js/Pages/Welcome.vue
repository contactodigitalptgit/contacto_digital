<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    canLogin?: boolean;
    canRegister?: boolean;
    laravelVersion?: string;
    phpVersion?: string;
}>();

const capabilityCards = [
    {
        eyebrow: 'Clientes',
        title: 'Contas, estados e acessos num painel muito mais claro.',
        description:
            'Acompanha cada cliente, destaca prioridades e mantém a operação organizada sem depender de folhas soltas ou mensagens dispersas.',
    },
    {
        eyebrow: 'Eventos',
        title: 'Cada evento com contexto, ritmo e controlo visual.',
        description:
            'Centraliza os eventos ativos, prepara a equipa e acompanha a execução a partir de uma vista pensada para decisões rápidas.',
    },
    {
        eyebrow: 'Dashboards',
        title: 'Leitura imediata para quem precisa agir sem perder tempo.',
        description:
            'Consulta indicadores, detalhes e estados num ambiente escuro, limpo e confortável para uso contínuo durante o dia inteiro.',
    },
    {
        eyebrow: 'Operacao',
        title: 'Uma entrada organizada para equipas que precisam decidir rapido.',
        description:
            'A homepage apresenta a plataforma com clareza, reforca a confianca no produto e conduz o utilizador para a acao certa sem exageros.',
    },
];

const focusPillars = [
    'Interface dark-first para uso profissional e prolongado',
    'Entrada direta para login, dashboard e operação diária',
    'Estrutura pensada para telemovel, tablet e desktop',
];

const workflowSteps = [
    {
        label: '01',
        title: 'Organiza a base',
        text: 'Clientes, eventos e acessos ficam num fluxo único, sem ruído visual.',
    },
    {
        label: '02',
        title: 'Acompanha o dia',
        text: 'Os painéis destacam o que está em curso, o que precisa de atenção e o que já está resolvido.',
    },
    {
        label: '03',
        title: 'Mantem o ritmo',
        text: 'A experiencia acompanha escritorio, reunioes e consultas rapidas no telemovel sem perder clareza.',
    },
];

const dashboardTiles = [
    {
        label: 'Clientes',
        value: 'Base organizada',
        accent: 'from-sky-500/30 via-sky-500/10 to-transparent',
    },
    {
        label: 'Eventos',
        value: 'Fluxo centralizado',
        accent: 'from-cyan-400/30 via-cyan-400/10 to-transparent',
    },
    {
        label: 'Relatorios',
        value: 'Leitura rapida',
        accent: 'from-indigo-500/30 via-indigo-500/10 to-transparent',
    },
    {
        label: 'Operacao',
        value: 'Rotina mais fluida',
        accent: 'from-emerald-400/30 via-emerald-400/10 to-transparent',
    },
];

const experienceChecks = [
    'Mensagem mais objetiva e sem ruido tecnico',
    'Leitura confortavel com foco em contraste e hierarquia',
    'Navegacao direta para login, dashboard e funcionalidades',
    'Visual consistente em desktop, tablet e telemovel',
];
</script>

<template>
    <Head title="Contacto Digital" />

    <div class="landing-shell min-h-screen overflow-hidden text-slate-100">
        <div aria-hidden="true" class="aurora aurora-left"></div>
        <div aria-hidden="true" class="aurora aurora-right"></div>

        <header class="relative z-10">
            <div
                class="mx-auto flex max-w-7xl items-center justify-between px-6 py-6 sm:px-8 lg:px-10"
            >
                <div class="flex items-center gap-4">
                    <div
                        class="flex h-14 w-14 items-center justify-center rounded-2xl border border-white/10 bg-white/5 shadow-[0_18px_60px_rgba(8,15,38,0.45)] backdrop-blur"
                    >
                        <img
                            src="/images/logo2.png"
                            alt="Contacto Digital"
                            class="h-9 w-auto"
                        />
                    </div>

                    <div>
                        <p
                            class="text-xs font-semibold uppercase tracking-[0.32em] text-sky-300/80"
                        >
                            Contacto Digital
                        </p>
                        <p class="text-sm text-slate-400">
                            Gestao moderna para clientes e eventos
                        </p>
                    </div>
                </div>

                <nav class="hidden items-center gap-8 text-sm text-slate-300 md:flex">
                    <a href="#capabilities" class="transition hover:text-white">
                        Funcionalidades
                    </a>
                    <a href="#experience" class="transition hover:text-white">
                        Experiencia
                    </a>
                    <Link
                        v-if="$page.props.auth.user"
                        :href="route('dashboard')"
                        class="inline-flex items-center rounded-full border border-sky-400/40 bg-sky-400/10 px-5 py-2.5 font-semibold text-sky-100 transition hover:border-sky-300/70 hover:bg-sky-400/20"
                    >
                        Abrir dashboard
                    </Link>

                    <template v-else-if="canLogin">
                        <Link
                            :href="route('login')"
                            class="inline-flex items-center rounded-full border border-sky-400/40 bg-sky-400/10 px-5 py-2.5 font-semibold text-sky-100 transition hover:border-sky-300/70 hover:bg-sky-400/20"
                        >
                            Entrar
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <main class="relative z-10">
            <section class="mx-auto max-w-7xl px-6 pb-20 pt-6 sm:px-8 lg:px-10">
                <div class="grid gap-12 lg:grid-cols-[1.08fr_0.92fr] lg:items-center">
                    <div class="max-w-3xl">
                        <div
                            class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-sm text-slate-300 backdrop-blur"
                        >
                            <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                            Home dark, moderna e alinhada com a operacao
                        </div>

                        <h1
                            class="mt-8 max-w-4xl text-5xl font-semibold leading-[1.02] tracking-tight text-white sm:text-6xl lg:text-7xl"
                        >
                            A primeira impressao do teu sistema agora parece
                            produto de verdade.
                        </h1>

                        <p
                            class="mt-6 max-w-2xl text-lg leading-8 text-slate-300 sm:text-xl"
                        >
                            Contacto Digital centraliza clientes, eventos,
                            dashboards e operacao diaria numa entrada escura e
                            elegante, preparada para browser, telemovel e
                            uso continuo pela equipa.
                        </p>

                        <div class="mt-10 flex flex-col gap-4 sm:flex-row">
                            <Link
                                v-if="$page.props.auth.user"
                                :href="route('dashboard')"
                                class="inline-flex items-center justify-center rounded-full bg-gradient-to-r from-sky-400 via-cyan-400 to-blue-500 px-7 py-3.5 text-base font-semibold text-slate-950 shadow-[0_18px_50px_rgba(37,99,235,0.35)] transition hover:scale-[1.01]"
                            >
                                Ir para o dashboard
                            </Link>

                            <Link
                                v-else-if="canLogin"
                                :href="route('login')"
                                class="inline-flex items-center justify-center rounded-full bg-gradient-to-r from-sky-400 via-cyan-400 to-blue-500 px-7 py-3.5 text-base font-semibold text-slate-950 shadow-[0_18px_50px_rgba(37,99,235,0.35)] transition hover:scale-[1.01]"
                            >
                                Entrar na plataforma
                            </Link>

                            <a
                                href="#capabilities"
                                class="inline-flex items-center justify-center rounded-full border border-white/15 bg-white/5 px-7 py-3.5 text-base font-semibold text-white backdrop-blur transition hover:border-white/25 hover:bg-white/10"
                            >
                                Ver funcionalidades
                            </a>

                            <Link
                                v-if="!$page.props.auth.user && canRegister"
                                :href="route('register')"
                                class="inline-flex items-center justify-center rounded-full border border-slate-700 bg-slate-900/70 px-7 py-3.5 text-base font-semibold text-slate-200 transition hover:border-slate-500 hover:bg-slate-900"
                            >
                                Criar conta
                            </Link>
                        </div>

                        <div class="mt-12 grid gap-4 sm:grid-cols-3">
                            <div
                                v-for="item in focusPillars"
                                :key="item"
                                class="rounded-2xl border border-white/10 bg-white/[0.04] px-5 py-5 text-sm leading-6 text-slate-300 shadow-[0_18px_60px_rgba(5,10,25,0.24)] backdrop-blur"
                            >
                                {{ item }}
                            </div>
                        </div>
                    </div>

                    <div class="relative">
                        <div class="landing-panel rounded-[2rem] p-4 sm:p-6">
                            <div
                                class="rounded-[1.6rem] border border-white/10 bg-slate-950/80 p-5 shadow-[0_24px_90px_rgba(4,11,31,0.55)] backdrop-blur"
                            >
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="h-2.5 w-2.5 rounded-full bg-rose-400/90"
                                        ></span>
                                        <span
                                            class="h-2.5 w-2.5 rounded-full bg-amber-300/90"
                                        ></span>
                                        <span
                                            class="h-2.5 w-2.5 rounded-full bg-emerald-400/90"
                                        ></span>
                                    </div>

                                    <div
                                        class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs uppercase tracking-[0.24em] text-slate-400"
                                    >
                                        Workspace
                                    </div>
                                </div>

                                <div
                                    class="mt-5 flex items-center justify-between gap-4 rounded-[1.4rem] border border-white/10 bg-white/[0.03] px-5 py-4"
                                >
                                    <div>
                                        <p
                                            class="text-xs uppercase tracking-[0.24em] text-slate-500"
                                        >
                                            Vista inicial
                                        </p>
                                        <h2
                                            class="mt-2 text-2xl font-semibold text-white"
                                        >
                                            Operacao com clareza visual
                                        </h2>
                                    </div>

                                    <div
                                        class="rounded-full border border-emerald-400/25 bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-300"
                                    >
                                        Online
                                    </div>
                                </div>

                                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                    <article
                                        v-for="tile in dashboardTiles"
                                        :key="tile.label"
                                        class="overflow-hidden rounded-[1.35rem] border border-white/10 bg-slate-900/80"
                                    >
                                        <div
                                            class="h-1.5 w-full bg-gradient-to-r"
                                            :class="tile.accent"
                                        ></div>
                                        <div class="p-4">
                                            <p
                                                class="text-xs uppercase tracking-[0.22em] text-slate-500"
                                            >
                                                {{ tile.label }}
                                            </p>
                                            <p
                                                class="mt-3 text-lg font-semibold text-white"
                                            >
                                                {{ tile.value }}
                                            </p>
                                        </div>
                                    </article>
                                </div>

                                <div
                                    class="mt-5 grid gap-4 lg:grid-cols-[1.18fr_0.82fr]"
                                >
                                    <div
                                        class="rounded-[1.4rem] border border-white/10 bg-white/[0.03] p-5"
                                    >
                                        <div
                                            class="flex items-center justify-between"
                                        >
                                            <div>
                                                <p
                                                    class="text-xs uppercase tracking-[0.24em] text-slate-500"
                                                >
                                                    Fluxo da plataforma
                                                </p>
                                                <p
                                                    class="mt-2 text-xl font-semibold text-white"
                                                >
                                                    Entrada preparada para
                                                    operacao real
                                                </p>
                                            </div>

                                            <div
                                                class="rounded-full border border-sky-400/25 bg-sky-400/10 px-3 py-1 text-xs font-semibold text-sky-200"
                                            >
                                                Dark mode
                                            </div>
                                        </div>

                                        <div class="mt-5 space-y-4">
                                            <div
                                                v-for="step in workflowSteps"
                                                :key="step.label"
                                                class="flex gap-4 rounded-2xl border border-white/6 bg-slate-950/50 px-4 py-4"
                                            >
                                                <div
                                                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-sky-400/25 to-cyan-400/10 text-sm font-semibold text-sky-200"
                                                >
                                                    {{ step.label }}
                                                </div>
                                                <div>
                                                    <h3
                                                        class="text-base font-semibold text-white"
                                                    >
                                                        {{ step.title }}
                                                    </h3>
                                                    <p
                                                        class="mt-1 text-sm leading-6 text-slate-400"
                                                    >
                                                        {{ step.text }}
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="space-y-4">
                                        <div
                                            class="rounded-[1.4rem] border border-white/10 bg-gradient-to-br from-sky-500/12 via-slate-950/90 to-cyan-500/10 p-5"
                                        >
                                            <p
                                                class="text-xs uppercase tracking-[0.24em] text-sky-200/75"
                                            >
                                                Acesso fluido
                                            </p>
                                            <h3
                                                class="mt-3 text-xl font-semibold text-white"
                                            >
                                                Clara em desktop, tablet e
                                                telemovel
                                            </h3>

                                            <div class="mt-4 flex flex-wrap gap-2">
                                                <span
                                                    class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300"
                                                >
                                                    Desktop
                                                </span>
                                                <span
                                                    class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300"
                                                >
                                                    Tablet
                                                </span>
                                                <span
                                                    class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-300"
                                                >
                                                    Telemovel
                                                </span>
                                            </div>
                                        </div>

                                        <div
                                            class="rounded-[1.4rem] border border-white/10 bg-white/[0.03] p-5"
                                        >
                                            <p
                                                class="text-xs uppercase tracking-[0.24em] text-slate-500"
                                            >
                                                Pontos de qualidade
                                            </p>

                                            <ul class="mt-4 space-y-3">
                                                <li
                                                    v-for="item in experienceChecks"
                                                    :key="item"
                                                    class="flex items-center gap-3 text-sm text-slate-300"
                                                >
                                                    <span
                                                        class="h-2.5 w-2.5 rounded-full bg-cyan-300"
                                                    ></span>
                                                    {{ item }}
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                id="capabilities"
                class="mx-auto max-w-7xl px-6 py-6 sm:px-8 lg:px-10"
            >
                <div
                    class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between"
                >
                    <div class="max-w-2xl">
                        <p
                            class="text-sm font-semibold uppercase tracking-[0.32em] text-sky-300/75"
                        >
                            Funcionalidades
                        </p>
                        <h2
                            class="mt-4 text-3xl font-semibold tracking-tight text-white sm:text-4xl"
                        >
                            Uma entrada premium para um sistema que precisa
                            transmitir controlo.
                        </h2>
                    </div>

                    <p class="max-w-xl text-base leading-7 text-slate-400">
                        A nova home foi pensada para vender organizacao,
                        seguranca visual e maturidade do produto logo no primeiro
                        scroll.
                    </p>
                </div>

                <div class="mt-10 grid gap-5 lg:grid-cols-2">
                    <article
                        v-for="card in capabilityCards"
                        :key="card.title"
                        class="feature-card rounded-[1.8rem] p-7"
                    >
                        <p
                            class="text-xs font-semibold uppercase tracking-[0.28em] text-sky-200/70"
                        >
                            {{ card.eyebrow }}
                        </p>
                        <h3 class="mt-4 text-2xl font-semibold text-white">
                            {{ card.title }}
                        </h3>
                        <p class="mt-4 max-w-xl text-base leading-7 text-slate-400">
                            {{ card.description }}
                        </p>
                    </article>
                </div>
            </section>

            <section
                id="experience"
                class="mx-auto max-w-7xl px-6 py-20 sm:px-8 lg:px-10"
            >
                <div class="grid gap-8 lg:grid-cols-[0.88fr_1.12fr]">
                    <div
                        class="rounded-[2rem] border border-white/10 bg-white/[0.04] p-8 shadow-[0_24px_90px_rgba(4,11,31,0.3)] backdrop-blur"
                    >
                        <p
                            class="text-sm font-semibold uppercase tracking-[0.32em] text-emerald-300/75"
                        >
                            Experiencia
                        </p>
                        <h2
                            class="mt-4 text-3xl font-semibold tracking-tight text-white"
                        >
                            Menos cara de template. Mais cara de plataforma.
                        </h2>
                        <p class="mt-5 text-base leading-7 text-slate-400">
                            O desenho escuro, as camadas translúcidas e os
                            contrastes azuis colocam o produto numa linguagem
                            mais premium sem perder legibilidade.
                        </p>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-3">
                        <article
                            class="rounded-[1.8rem] border border-white/10 bg-slate-950/70 p-6"
                        >
                            <p
                                class="text-xs uppercase tracking-[0.26em] text-slate-500"
                            >
                                Visual
                            </p>
                            <h3 class="mt-4 text-xl font-semibold text-white">
                                Dark-first
                            </h3>
                            <p class="mt-3 text-sm leading-6 text-slate-400">
                                Gradientes fundos, paines em vidro e foco total
                                na leitura.
                            </p>
                        </article>

                        <article
                            class="rounded-[1.8rem] border border-white/10 bg-slate-950/70 p-6"
                        >
                            <p
                                class="text-xs uppercase tracking-[0.26em] text-slate-500"
                            >
                                Conversao
                            </p>
                            <h3 class="mt-4 text-xl font-semibold text-white">
                                CTA imediato
                            </h3>
                            <p class="mt-3 text-sm leading-6 text-slate-400">
                                Login, dashboard e funcionalidades aparecem sem
                                friccao logo no primeiro ecrã.
                            </p>
                        </article>

                        <article
                            class="rounded-[1.8rem] border border-white/10 bg-slate-950/70 p-6"
                        >
                            <p
                                class="text-xs uppercase tracking-[0.26em] text-slate-500"
                            >
                                Consistencia
                            </p>
                            <h3 class="mt-4 text-xl font-semibold text-white">
                                Fluida em qualquer ecra
                            </h3>
                            <p class="mt-3 text-sm leading-6 text-slate-400">
                                A mesma identidade visual segura mantem-se em
                                desktop, tablet e telemovel.
                            </p>
                        </article>
                    </div>
                </div>
            </section>

            <section class="mx-auto max-w-7xl px-6 pb-24 sm:px-8 lg:px-10">
                <div
                    class="cta-shell overflow-hidden rounded-[2.2rem] border border-sky-300/15 px-8 py-10 sm:px-10 sm:py-12"
                >
                    <div
                        class="flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between"
                    >
                        <div class="max-w-2xl">
                            <p
                                class="text-sm font-semibold uppercase tracking-[0.32em] text-sky-200/75"
                            >
                                Contacto Digital
                            </p>
                            <h2
                                class="mt-4 text-3xl font-semibold tracking-tight text-white sm:text-4xl"
                            >
                                Uma home com mais presenca, mais credibilidade e
                                muito mais cara de produto profissional.
                            </h2>
                        </div>

                        <div class="flex flex-col gap-3 sm:flex-row">
                            <Link
                                v-if="$page.props.auth.user"
                                :href="route('dashboard')"
                                class="inline-flex items-center justify-center rounded-full bg-white px-6 py-3 font-semibold text-slate-950 transition hover:bg-slate-100"
                            >
                                Abrir dashboard
                            </Link>

                            <Link
                                v-else-if="canLogin"
                                :href="route('login')"
                                class="inline-flex items-center justify-center rounded-full bg-white px-6 py-3 font-semibold text-slate-950 transition hover:bg-slate-100"
                            >
                                Entrar agora
                            </Link>

                            <a
                                href="#experience"
                                class="inline-flex items-center justify-center rounded-full border border-white/15 bg-white/5 px-6 py-3 font-semibold text-white transition hover:bg-white/10"
                            >
                                Ver experiencia
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>

<style scoped>
.landing-shell {
    position: relative;
    isolation: isolate;
    background:
        radial-gradient(circle at 12% 18%, rgba(14, 165, 233, 0.2), transparent 24%),
        radial-gradient(circle at 88% 12%, rgba(59, 130, 246, 0.18), transparent 22%),
        linear-gradient(180deg, #020617 0%, #050b17 48%, #030712 100%);
}

.landing-shell::before {
    content: '';
    position: fixed;
    inset: 0;
    z-index: -2;
    pointer-events: none;
    background-image:
        linear-gradient(rgba(148, 163, 184, 0.08) 1px, transparent 1px),
        linear-gradient(90deg, rgba(148, 163, 184, 0.08) 1px, transparent 1px);
    background-size: 72px 72px;
    mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.45), transparent 85%);
    opacity: 0.2;
}

.landing-shell::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    pointer-events: none;
    background: linear-gradient(
        180deg,
        rgba(255, 255, 255, 0.02) 0%,
        transparent 18%,
        transparent 80%,
        rgba(255, 255, 255, 0.015) 100%
    );
}

.aurora {
    position: absolute;
    z-index: -1;
    border-radius: 9999px;
    filter: blur(68px);
    opacity: 0.55;
    animation: float 14s ease-in-out infinite;
}

.aurora-left {
    left: -6rem;
    top: 5rem;
    height: 18rem;
    width: 18rem;
    background: rgba(56, 189, 248, 0.24);
}

.aurora-right {
    right: -5rem;
    top: 8rem;
    height: 21rem;
    width: 21rem;
    background: rgba(59, 130, 246, 0.22);
    animation-delay: -5s;
}

.landing-panel {
    position: relative;
    border: 1px solid rgba(148, 163, 184, 0.12);
    background:
        linear-gradient(180deg, rgba(15, 23, 42, 0.78), rgba(2, 6, 23, 0.82)),
        radial-gradient(circle at top right, rgba(59, 130, 246, 0.18), transparent 38%);
    box-shadow:
        0 28px 90px rgba(2, 6, 23, 0.55),
        inset 0 1px 0 rgba(255, 255, 255, 0.06);
}

.feature-card {
    border: 1px solid rgba(148, 163, 184, 0.12);
    background:
        linear-gradient(180deg, rgba(15, 23, 42, 0.78), rgba(2, 6, 23, 0.85)),
        radial-gradient(circle at top right, rgba(34, 211, 238, 0.09), transparent 36%);
    box-shadow: 0 24px 72px rgba(2, 6, 23, 0.32);
}

.cta-shell {
    position: relative;
    background:
        linear-gradient(135deg, rgba(14, 165, 233, 0.18), rgba(2, 6, 23, 0.72) 45%, rgba(37, 99, 235, 0.22)),
        #050b17;
    box-shadow:
        0 26px 90px rgba(2, 6, 23, 0.38),
        inset 0 1px 0 rgba(255, 255, 255, 0.06);
}

.cta-shell::after {
    content: '';
    position: absolute;
    inset: 1px;
    border-radius: 2rem;
    pointer-events: none;
    border: 1px solid rgba(255, 255, 255, 0.05);
}

@keyframes float {
    0%,
    100% {
        transform: translate3d(0, 0, 0) scale(1);
    }

    50% {
        transform: translate3d(0, 18px, 0) scale(1.04);
    }
}
</style>
