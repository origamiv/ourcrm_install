<x-app-layout>
    <x-slot name="title">
        Статус синхронизации
    </x-slot>

    <!-- Bootstrap CSS for compatibility with existing sync UI -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>

    <style>
        .progress { height: 25px; }
        .progress-bar { font-size: 0.9rem; line-height: 25px; }
        [v-cloak] { display: none; }
        /* Fix for bootstrap conflict with tailwind if any */
        .card { margin-bottom: 1rem; }
    </style>

    <div id="app" v-cloak>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2">Статус синхронизации</h1>
            <div class="text-muted">
                Обновлено: @{{ lastUpdate }}
                <span v-if="loading" class="spinner-border spinner-border-sm ms-2"></span>
            </div>
        </div>

        <!-- Общие прогресс-бары -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card border-primary shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="card-title text-primary h5">
                            <i class="bi bi-table me-2"></i>Прогресс по таблицам
                        </h3>
                        <div class="d-flex justify-content-between mb-2">
                            <strong>Всего таблиц: @{{ stats.totalTablesAll }}</strong>
                            <span>Завершено: @{{ stats.completedTablesAll }} (@{{ stats.overallProgressTables }}%)</span>
                        </div>
                        <div class="progress" style="height: 35px;">
                            <div class="progress-bar bg-primary progress-bar-striped progress-bar-animated" role="progressbar" :style="{ width: stats.overallProgressTables + '%' }">
                                <h5 class="mb-0" style="font-size: 1rem;">@{{ stats.overallProgressTables }}%</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-info shadow-sm h-100">
                    <div class="card-body">
                        <h3 class="card-title text-info h5">
                            <i class="bi bi-database me-2"></i>Прогресс по записям
                        </h3>
                        <div class="d-flex justify-content-between mb-2">
                            <strong>Всего записей (One): @{{ formatNumber(stats.totalRowsOne) }}</strong>
                            <span>Синхронизировано (Two): @{{ formatNumber(stats.totalRowsTwo) }} (@{{ stats.overallProgressRows }}%)</span>
                        </div>
                        <div class="progress" style="height: 35px;">
                            <div class="progress-bar bg-info progress-bar-striped progress-bar-animated" role="progressbar" :style="{ width: stats.overallProgressRows + '%' }">
                                <h5 class="mb-0 text-dark" style="font-size: 1rem;">@{{ stats.overallProgressRows }}%</h5>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card border-warning shadow-sm">
                    <div class="card-body">
                        <h3 class="card-title text-warning h5">
                            <i class="bi bi-bar-chart-steps me-2"></i>Топ-10 больших таблиц
                        </h3>
                        <div class="row">
                            <div v-for="table in topTables" :key="table.full_name" class="col-md-6 mb-2">
                                <div class="d-flex justify-content-between align-items-center p-2 border rounded bg-white">
                                    <span class="text-truncate">@{{ table.full_name }}</span>
                                    <span class="badge bg-secondary rounded-pill">@{{ formatNumber(table.count_one) }} записей</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Прогресс по схемам -->
        <div class="row mb-5">
            <div class="col-md-12">
                <h3 class="h4">Прогресс по схемам</h3>
                <div v-for="schema in schemas" :key="schema.name" class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <strong>@{{ schema.name }}</strong>
                        <span>@{{ schema.completed }} / @{{ schema.total }} таблиц (@{{ schema.progress }}%)</span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar bg-success" role="progressbar" :style="{ width: schema.progress + '%' }">
                            @{{ schema.progress }}%
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Детализация по таблицам -->
        <div class="row">
            <div class="col-md-12">
                <h3 class="h4">Детализация по таблицам</h3>

                <!-- Mobile Cards View -->
                <div class="d-md-none">
                    <div v-for="row in syncData" :key="'card-' + row.id" class="card mb-3 shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><strong>@{{ row.schema_name }}.@{{ row.table_name }}</strong></h6>
                            <span class="badge" :class="getStatusBadgeClass(row.status)">@{{ row.status }}</span>
                        </div>
                        <div class="card-body">
                            <div class="row mb-2">
                                <div class="col-6 text-muted small">Count One:</div>
                                <div class="col-6 text-end">@{{ formatNumber(row.count_one) }}</div>
                                <div class="col-6 text-muted small">Count Two:</div>
                                <div class="col-6 text-end">@{{ formatNumber(row.count_two) }}</div>
                            </div>
                            <div class="progress mb-1" style="height: 15px;">
                                <div class="progress-bar" :class="row.completion_percentage >= 100 ? 'bg-success' : 'bg-primary'" role="progressbar" :style="{ width: row.completion_percentage + '%' }">
                                    @{{ row.completion_percentage }}%
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="badge" :class="row.is_trigger_active ? 'bg-success' : 'bg-danger'">
                                    Trigger: @{{ row.is_trigger_active ? 'Active' : 'Inactive' }}
                                </span>
                                <small class="text-muted">@{{ row.updated_at }}</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Desktop Table View -->
                <div class="table-responsive d-none d-md-block">
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
                            <tr v-for="row in syncData" :key="row.id">
                                <td>@{{ row.schema_name }}</td>
                                <td>@{{ row.table_name }}</td>
                                <td>@{{ formatNumber(row.count_one) }}</td>
                                <td>@{{ formatNumber(row.count_two) }}</td>
                                <td>
                                    <div class="progress" style="height: 10px;">
                                        <div class="progress-bar" :class="row.completion_percentage >= 100 ? 'bg-success' : 'bg-primary'" role="progressbar" :style="{ width: row.completion_percentage + '%' }"></div>
                                    </div>
                                    <small>@{{ row.completion_percentage }}%</small>
                                </td>
                                <td>
                                    <span class="badge" :class="getStatusBadgeClass(row.status)">
                                        @{{ row.status }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge" :class="row.is_trigger_active ? 'bg-success' : 'bg-danger'">
                                        @{{ row.is_trigger_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td>@{{ row.updated_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        const { createApp, ref, onMounted } = Vue;

        createApp({
            setup() {
                const syncData = ref([]);
                const schemas = ref([]);
                const topTables = ref([]);
                const stats = ref({
                    overallProgressTables: 0,
                    totalTablesAll: 0,
                    completedTablesAll: 0,
                    overallProgressRows: 0,
                    totalRowsOne: 0,
                    totalRowsTwo: 0
                });
                const loading = ref(false);
                const lastUpdate = ref('');

                const fetchData = async () => {
                    loading.value = true;
                    try {
                        const response = await fetch('/sync/data');
                        const data = await response.json();

                        syncData.value = data.syncData;
                        schemas.value = data.schemas;
                        topTables.value = data.topTables;
                        stats.value = {
                            overallProgressTables: data.overallProgressTables,
                            totalTablesAll: data.totalTablesAll,
                            completedTablesAll: data.completedTablesAll,
                            overallProgressRows: data.overallProgressRows,
                            totalRowsOne: data.totalRowsOne,
                            totalRowsTwo: data.totalRowsTwo
                        };
                        lastUpdate.value = new Date().toLocaleTimeString();
                    } catch (error) {
                        console.error('Error fetching data:', error);
                    } finally {
                        loading.value = false;
                    }
                };
                const formatNumber = (num) => {
                    return new Intl.NumberFormat('ru-RU').format(num);
                };

                const getStatusBadgeClass = (status) => {
                    switch (status) {
                        case 'completed': return 'bg-success';
                        case 'syncing': return 'bg-warning';
                        default: return 'bg-secondary';
                    }
                };

                onMounted(() => {
                    fetchData();
                    setInterval(fetchData, 5000); // Обновление каждые 5 секунд
                });

                return {
                    syncData,
                    schemas,
                    topTables,
                    stats,
                    loading,
                    lastUpdate,
                    formatNumber,
                    getStatusBadgeClass
                };
            }
        }).mount('#app');
    </script>
</x-app-layout>
