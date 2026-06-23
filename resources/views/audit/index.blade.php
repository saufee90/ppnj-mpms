@extends('layouts.app')
@section('title', 'Log Aktiviti')

@section('content')
<div class="bg-white rounded-xl shadow-sm overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
            <tr>
                <th class="px-4 py-3 text-left">Masa</th>
                <th class="px-4 py-3 text-left">Pengguna</th>
                <th class="px-4 py-3 text-left">Tindakan</th>
                <th class="px-4 py-3 text-left">Jenis Data</th>
                <th class="px-4 py-3 text-left">IP</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse($logs as $log)
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                <td class="px-4 py-3">{{ $log->user->name ?? '-' }}</td>
                <td class="px-4 py-3">
                    <span class="px-2 py-1 rounded-full text-xs bg-gray-100">{{ $log->action }}</span>
                </td>
                <td class="px-4 py-3">{{ $log->model_type ?? '-' }} @if($log->model_id) #{{ $log->model_id }} @endif</td>
                <td class="px-4 py-3 text-gray-400">{{ $log->ip_address }}</td>
            </tr>
            @empty
            <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400">Tiada log aktiviti.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
