export interface CallRow {
    id: number;
    direction: 'inbound' | 'outbound' | 'internal';
    status: string;
    from_number: string | null;
    from_name: string | null;
    to_number: string | null;
    to_name: string | null;
    did: string | null;
    started_at: string;
    answered_at: string | null;
    ended_at: string | null;
    duration: number | null;
    talk_time: number | null;
    hangup_cause: string | null;
    has_recording: boolean | null;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}
