export const reportDayBoundary = (
    eventDate: string,
    boundary: 'start' | 'end',
): string => {
    const date = eventDate.slice(0, 10);

    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
        return '';
    }

    return `${date}T${boundary === 'start' ? '00:00:00' : '23:59:59'}`;
};

export const shouldFollowEventDay = (
    currentValue: string,
    previousEventDate: string | undefined,
    boundary: 'start' | 'end',
): boolean => currentValue === ''
    || (previousEventDate !== undefined
        && currentValue === reportDayBoundary(previousEventDate, boundary));
