import {
  ColumnDef,
  flexRender,
  getCoreRowModel,
  getSortedRowModel,
  useReactTable,
  SortingState,
  type OnChangeFn,
  type RowData,
} from '@tanstack/react-table';
import { useState } from 'react';
import { ChevronUp, ChevronDown, ChevronsUpDown } from 'lucide-react';
import { cn } from '@/lib/utils';

declare module '@tanstack/react-table' {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  interface ColumnMeta<TData extends RowData, TValue> {
    width?: string;
  }
}

interface DataTableProps<TData, TValue> {
  columns: ColumnDef<TData, TValue>[];
  data: TData[];
  sorting?: SortingState;
  onSortingChange?: (sorting: SortingState) => void;
  onRowClick?: (row: TData) => void;
}

export function DataTable<TData, TValue>({ columns, data, sorting, onSortingChange, onRowClick }: DataTableProps<TData, TValue>) {
  const [internalSorting, setInternalSorting] = useState<SortingState>([]);
  const sortingState = sorting ?? internalSorting;
  const setSorting = onSortingChange ?? setInternalSorting;

  const table = useReactTable({
    data,
    columns,
    getCoreRowModel: getCoreRowModel(),
    getSortedRowModel: getSortedRowModel(),
    state: { sorting: sortingState },
    onSortingChange: setSorting as OnChangeFn<SortingState>,
  });

  return (
    <div className="overflow-x-auto rounded-md border">
      <table className="w-full table-fixed text-sm">
        <thead className="bg-muted/50">
          {table.getHeaderGroups().map((headerGroup) => (
            <tr key={headerGroup.id}>
              {headerGroup.headers.map((header) => (
                <th
                  key={header.id}
                  className={cn('px-4 py-3 text-left font-medium', header.column.columnDef.meta?.width)}
                  style={{ width: header.column.columnDef.meta?.width }}
                >
                  {header.isPlaceholder ? null : (
                    <div
                      className={cn('flex items-center gap-1', header.column.getCanSort() ? 'cursor-pointer select-none' : '')}
                      onClick={header.column.getToggleSortingHandler()}
                    >
                      {flexRender(header.column.columnDef.header, header.getContext())}
                      {header.column.getCanSort() && (
                        <span className="ml-1">
                          {{ asc: <ChevronUp className="h-4 w-4" />, desc: <ChevronDown className="h-4 w-4" /> }[header.column.getIsSorted() as string] ?? <ChevronsUpDown className="h-4 w-4 opacity-50" />}
                        </span>
                      )}
                    </div>
                  )}
                </th>
              ))}
            </tr>
          ))}
        </thead>
        <tbody>
          {table.getRowModel().rows?.length ? (
            table.getRowModel().rows.map((row) => (
              <tr
                key={row.id}
                className={cn('border-t hover:bg-muted/30', onRowClick ? 'cursor-pointer' : '')}
                onClick={onRowClick ? () => onRowClick(row.original) : undefined}
              >
                {row.getVisibleCells().map((cell) => (
                  <td key={cell.id} className={cn('px-4 py-3', cell.column.columnDef.meta?.width)} style={{ width: cell.column.columnDef.meta?.width }}>
                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                  </td>
                ))}
              </tr>
            ))
          ) : (
            <tr>
              <td colSpan={columns.length} className="px-4 py-8 text-center text-muted-foreground">
                Žádné záznamy.
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}
