export type Operator = {
    operator_id: number;
    name: string;
};

export type Auth = {
    operator: Operator | null;
};
