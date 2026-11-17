const utcDateFormatter = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'UTC',
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

export function formatUtcDate(value: string): string {
    return `${utcDateFormatter.format(new Date(value))} UTC`;
}
