<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .progress { height: 25px; }
        .progress-bar { font-size: 0.9rem; line-height: 25px; }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-5">
        <h1 class="mb-4">Статус синхронизации</h1>

        <div class="row mb-5">
            <div class="col-md-12">
                <h3>Прогресс по схемам</h3>
                @foreach($schemas as $schema)
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <strong>{{ $schema['name'] }}</strong>
                            <span>{{ $schema['completed'] }} / {{ $schema['total'] }} таблиц ({{ $schema['progress'] }}%)</span>
                        </div>
                        <div class="progress">
                            <div class="progress-bar bg-success" role="progressbar" style="width: {{ $schema['progress'] }}%" aria-valuenow="{{ $schema['progress'] }}" aria-valuemin="0" aria-valuemax="100">
                                {{ $schema['progress'] }}%
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <h3>Детализация по таблицам</h3>
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-bordered bg-white">
                        <thead class="table-dark">
                            <tr>
                                <th>Схема</th>
                                <th>Таблица</th>
                                <th>Count One</th>
                                <th>Count Two</th>
                                <th>Прогресс (%)</th>
                                <th>Статус</th>
                                <th>Триггер</th>
                                <th>Обновлено</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($syncData as $row)
                                <tr>
                                    <td>{{ $row->schema_name }}</td>
                                    <td>{{ $row->table_name }}</td>
                                    <td>{{ number_format($row->count_one) }}</td>
                                    <td>{{ number_format($row->count_two) }}</td>
                                    <td>
                                        <div class="progress" style="height: 10px;">
                                            <div class="progress-bar {{ $row->completion_percentage >= 100 ? 'bg-success' : 'bg-primary' }}" role="progressbar" style="width: {{ $row->completion_percentage }}%"></div>
                                        </div>
                                        <small>{{ $row->completion_percentage }}%</small>
                                    </td>
                                    <td>
                                        <span class="badge {{ $row->status === 'completed' ? 'bg-success' : ($row->status === 'syncing' ? 'bg-warning' : 'bg-secondary') }}">
                                            {{ $row->status }}
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge {{ $row->is_trigger_active ? 'bg-success' : 'bg-danger' }}">
                                            {{ $row->is_trigger_active ? 'Active' : 'Inactive' }}
                                        </span>
                                    </td>
                                    <td>{{ $row->updated_at }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
