import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';

export interface InvoicePayment {
  id: number;
  invoice_id: number;
  amount_cents: number;
  payment_date: string;
  method: 'cash' | 'bank_transfer' | 'card' | 'other';
  note: string | null;
  created_at: string;
}

export interface InvoicePaymentInput {
  invoice_id: number;
  amount_cents: number;
  payment_date: string;
  method?: 'cash' | 'bank_transfer' | 'card' | 'other';
  note?: string | null;
}

export interface InvoicePaymentUpdateInput {
  amount_cents?: number;
  payment_date?: string;
  method?: 'cash' | 'bank_transfer' | 'card' | 'other';
  note?: string | null;
}

export interface InvoicePaymentsResponse {
  data: InvoicePayment[];
}

export function useInvoicePayments(invoiceId: number | null) {
  return useQuery<InvoicePaymentsResponse>({
    queryKey: ['invoice-payments', invoiceId],
    queryFn: () => api.get<InvoicePaymentsResponse>(`/invoice-payments?invoice_id=${invoiceId}`),
    enabled: !!invoiceId && invoiceId > 0,
  });
}

export function useCreateInvoicePayment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: InvoicePaymentInput) => api.post<InvoicePayment>('/invoice-payments', data),
    onSuccess: (_data, variables) => {
      qc.invalidateQueries({ queryKey: ['invoice-payments', variables.invoice_id] });
      qc.invalidateQueries({ queryKey: ['invoices'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      qc.invalidateQueries({ queryKey: ['finance-overview'] });
    },
  });
}

export function useUpdateInvoicePayment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, data }: { id: number; data: InvoicePaymentUpdateInput }) =>
      api.put<InvoicePayment>(`/invoice-payments/${id}`, data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['invoice-payments'] });
      qc.invalidateQueries({ queryKey: ['invoices'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      qc.invalidateQueries({ queryKey: ['finance-overview'] });
    },
  });
}

export function useDeleteInvoicePayment() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id }: { id: number; invoiceId: number }) =>
      api.delete(`/invoice-payments/${id}`),
    onMutate: async (variables: { id: number; invoiceId: number }) => {
      const queryKey = ['invoice-payments', variables.invoiceId];
      await qc.cancelQueries({ queryKey });
      const previous = qc.getQueryData<InvoicePaymentsResponse>(queryKey);
      if (previous) {
        qc.setQueryData<InvoicePaymentsResponse>(queryKey, {
          ...previous,
          data: previous.data.filter((p) => p.id !== variables.id),
        });
      }
      return { previous, invoiceId: variables.invoiceId };
    },
    onError: (_err, _variables, context) => {
      if (context?.previous) {
        qc.setQueryData(['invoice-payments', context.invoiceId], context.previous);
      }
    },
    onSettled: (_data, _error, variables) => {
      qc.invalidateQueries({ queryKey: ['invoice-payments', variables.invoiceId] });
      qc.invalidateQueries({ queryKey: ['invoices'] });
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      qc.invalidateQueries({ queryKey: ['finance-overview'] });
    },
  });
}
