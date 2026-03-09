import Swal from 'sweetalert2';

interface ConfirmActionOptions {
    title: string;
    text: string;
    confirmButtonText: string;
    cancelButtonText?: string;
}

const isDarkTheme = () =>
    document.documentElement.getAttribute('data-theme') === 'dark';

const getThemeColors = () => {
    if (isDarkTheme()) {
        return {
            background: '#0e1728',
            color: '#e2e8f0',
            confirmButtonColor: '#216fe0',
            cancelButtonColor: '#34465f',
        };
    }

    return {
        background: '#fbfdff',
        color: '#1f2937',
        confirmButtonColor: '#1564e8',
        cancelButtonColor: '#64748b',
    };
};

export const confirmAction = async (
    options: ConfirmActionOptions,
): Promise<boolean> => {
    const colors = getThemeColors();

    const result = await Swal.fire({
        icon: 'warning',
        title: options.title,
        text: options.text,
        showCancelButton: true,
        confirmButtonText: options.confirmButtonText,
        cancelButtonText: options.cancelButtonText ?? 'Cancelar',
        reverseButtons: true,
        background: colors.background,
        color: colors.color,
        confirmButtonColor: colors.confirmButtonColor,
        cancelButtonColor: colors.cancelButtonColor,
    });

    return result.isConfirmed;
};

export const showSuccessToast = async (title: string): Promise<void> => {
    const colors = getThemeColors();

    await Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title,
        showConfirmButton: false,
        timer: 2200,
        timerProgressBar: true,
        background: colors.background,
        color: colors.color,
    });
};

export const showErrorToast = async (title: string): Promise<void> => {
    const colors = getThemeColors();

    await Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'error',
        title,
        showConfirmButton: false,
        timer: 2600,
        timerProgressBar: true,
        background: colors.background,
        color: colors.color,
    });
};
