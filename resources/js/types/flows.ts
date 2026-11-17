/** How visits arrive: channel to landing page, from the dashboard's flows prop. */
export type Flows = {
    nodes: {
        sources: { name: string; visits: number }[];
        pages: { name: string; visits: number }[];
    };
    links: { source: string; page: string; visits: number }[];
};
