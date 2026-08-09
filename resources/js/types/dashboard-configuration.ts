export type DashboardSectionKey =
    | 'summary'
    | 'products'
    | 'zones'
    | 'reconciliation'
    | 'comparison'
    | 'highlights'
    | 'charts';

export type DashboardBlockArea = 'summary' | 'charts';
export type DashboardMetricGroup = 'movement' | 'payments' | 'top_up' | 'operations';

export interface DashboardConfigurationItem {
    key: string;
    label: string;
    helper: string;
    visible: boolean;
    available: boolean;
    requires_zt: boolean;
    area?: DashboardBlockArea;
    group?: DashboardMetricGroup;
}

export interface DashboardConfiguration {
    version: number;
    preset: string;
    sections: DashboardConfigurationItem[];
    blocks: DashboardConfigurationItem[];
    metrics: DashboardConfigurationItem[];
}

export interface DashboardPreset {
    key: string;
    label: string;
    description: string;
    configuration: DashboardConfiguration;
}

export interface DashboardEditorMeta {
    enabled: boolean;
    update_url: string;
    default_configuration: DashboardConfiguration;
    presets: DashboardPreset[];
}
