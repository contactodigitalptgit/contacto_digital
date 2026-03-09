import { computed, onMounted, onUnmounted, ref } from 'vue';

const deferredPrompt = ref<BeforeInstallPromptEvent | null>(null);
const standalone = ref(false);

const updateStandaloneState = () => {
    const isStandaloneMode =
        window.matchMedia('(display-mode: standalone)').matches ||
        // iOS Safari standalone detection
        (window.navigator as Navigator & { standalone?: boolean }).standalone ===
            true;

    standalone.value = isStandaloneMode;
    document.documentElement.setAttribute(
        'data-standalone',
        isStandaloneMode ? 'true' : 'false',
    );
};

const handleBeforeInstallPrompt = (event: Event) => {
    const promptEvent = event as BeforeInstallPromptEvent;
    promptEvent.preventDefault();
    deferredPrompt.value = promptEvent;
};

const handleAppInstalled = () => {
    deferredPrompt.value = null;
    updateStandaloneState();
};

export const usePwaInstall = () => {
    const canInstallPwa = computed(
        () => !standalone.value && deferredPrompt.value !== null,
    );

    const installPwa = async (): Promise<boolean> => {
        if (!deferredPrompt.value) {
            return false;
        }

        const promptEvent = deferredPrompt.value;
        deferredPrompt.value = null;

        await promptEvent.prompt();
        const result = await promptEvent.userChoice;
        updateStandaloneState();

        return result.outcome === 'accepted';
    };

    onMounted(() => {
        updateStandaloneState();
        window.addEventListener(
            'beforeinstallprompt',
            handleBeforeInstallPrompt,
        );
        window.addEventListener('appinstalled', handleAppInstalled);
        window
            .matchMedia('(display-mode: standalone)')
            .addEventListener('change', updateStandaloneState);
    });

    onUnmounted(() => {
        window.removeEventListener(
            'beforeinstallprompt',
            handleBeforeInstallPrompt,
        );
        window.removeEventListener('appinstalled', handleAppInstalled);
        window
            .matchMedia('(display-mode: standalone)')
            .removeEventListener('change', updateStandaloneState);
    });

    return {
        canInstallPwa,
        installPwa,
        isStandalonePwa: standalone,
    };
};
