<x-app-layout>
    <x-slot name="title">Служебное</x-slot>

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <h1 class="text-2xl font-bold mb-6 text-gray-800">Служебное</h1>

    <div class="space-y-6">

        {{-- ===== Widget 1: Git Merge ===== --}}
        <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-700">Слияние веток (Git Merge)</h2>
                <p class="text-sm text-gray-500 mt-1">Поставить задачу на мерж одной ветки в другую в выбранном проекте.</p>
            </div>
            <div class="p-6" id="git-merge-widget">
                <form id="git-merge-form" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        {{-- Site --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Сайт (проект)</label>
                            @if(count($sites))
                                <select id="gm-project" name="project"
                                    class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400"
                                    onchange="gitMergeLoadBranches(this.value)">
                                    <option value="">— выберите сайт —</option>
                                    @foreach($sites as $site)
                                        <option value="{{ $site }}">{{ $site }}.our24.ru</option>
                                    @endforeach
                                </select>
                            @else
                                <input id="gm-project" name="project" type="text" placeholder="Название проекта"
                                    class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400"
                                    oninput="gitMergeLoadBranches(this.value)">
                            @endif
                        </div>

                        {{-- From branch --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Ветка источник (from)</label>
                            <input id="gm-from" name="from_branch" type="text" list="gm-branches-list"
                                placeholder="claude/feature-abc"
                                class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                            <datalist id="gm-branches-list"></datalist>
                        </div>

                        {{-- To branch --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Ветка назначение (to)</label>
                            <input id="gm-to" name="to_branch" type="text" list="gm-branches-list"
                                placeholder="master"
                                class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <button type="submit"
                            class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-400 disabled:opacity-50"
                            id="gm-submit">
                            Запустить мерж
                        </button>
                        <span id="gm-result" class="text-sm"></span>
                    </div>
                </form>
            </div>
        </div>

        {{-- ===== Widget 2: Redis Command ===== --}}
        <div class="bg-white shadow-sm sm:rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-700">Отправить команду в Redis</h2>
                <p class="text-sm text-gray-500 mt-1">
                    Записывает JSON-payload в ключ <code class="bg-gray-100 px-1 rounded">{сайт}/commands</code> в Redis.
                    Сайт подхватит команду при следующем опросе Redis.
                </p>
            </div>
            <div class="p-6">
                <form id="redis-form" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        {{-- Site --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Сайт</label>
                            @if(count($sites))
                                <select id="rc-site" name="site"
                                    class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                                    <option value="">— выберите сайт —</option>
                                    @foreach($sites as $site)
                                        <option value="{{ $site }}">{{ $site }}.our24.ru</option>
                                    @endforeach
                                </select>
                            @else
                                <input id="rc-site" name="site" type="text" placeholder="Название сайта"
                                    class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                            @endif
                        </div>

                        {{-- Command --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Команда (action)</label>
                            <input id="rc-command" name="command" type="text" list="rc-commands-list"
                                placeholder="deploy"
                                class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                            <datalist id="rc-commands-list">
                                <option value="deploy">deploy — запустить деплой</option>
                                <option value="restart">restart — перезапустить процессы</option>
                                <option value="migrate">migrate — запустить миграции</option>
                                <option value="cache:clear">cache:clear — очистить кэш</option>
                                <option value="queue:restart">queue:restart — перезапустить очередь</option>
                            </datalist>
                        </div>

                        {{-- Parameters --}}
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Параметры
                                <span class="text-gray-400 font-normal">(JSON, необязательно)</span>
                            </label>
                            <input id="rc-params" name="parameters" type="text"
                                placeholder='{"branch": "master"}'
                                class="w-full border border-gray-300 rounded-md px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        </div>
                    </div>

                    {{-- Payload preview --}}
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Предпросмотр payload</label>
                        <pre id="rc-preview"
                            class="bg-gray-50 border border-gray-200 rounded-md px-3 py-2 text-xs text-gray-600 min-h-[3rem]">{}</pre>
                    </div>

                    <div class="flex items-center gap-4">
                        <button type="submit"
                            class="px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-400 disabled:opacity-50"
                            id="rc-submit">
                            Отправить в Redis
                        </button>
                        <span id="rc-result" class="text-sm"></span>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <script>
        const CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        // ───────────────── Git Merge Widget ─────────────────

        let gmBranchCache = {};

        async function gitMergeLoadBranches(project) {
            if (!project) return;
            if (gmBranchCache[project]) {
                fillBranchesList(gmBranchCache[project]);
                return;
            }
            try {
                const res = await fetch(`/service/branches/${encodeURIComponent(project)}`);
                const data = await res.json();
                gmBranchCache[project] = data.branches || [];
                fillBranchesList(gmBranchCache[project]);
            } catch (e) {
                // silence — branches are still typeable manually
            }
        }

        function fillBranchesList(branches) {
            const list = document.getElementById('gm-branches-list');
            list.innerHTML = '';
            branches.forEach(b => {
                const opt = document.createElement('option');
                opt.value = b;
                list.appendChild(opt);
            });
        }

        document.getElementById('git-merge-form').addEventListener('submit', async function (e) {
            e.preventDefault();
            const project   = document.getElementById('gm-project').value.trim();
            const fromBranch = document.getElementById('gm-from').value.trim();
            const toBranch   = document.getElementById('gm-to').value.trim();
            const btn        = document.getElementById('gm-submit');
            const result     = document.getElementById('gm-result');

            if (!project || !fromBranch || !toBranch) {
                result.className = 'text-sm text-red-600';
                result.textContent = 'Заполните все поля.';
                return;
            }

            btn.disabled = true;
            result.className = 'text-sm text-gray-500';
            result.textContent = 'Отправка…';

            try {
                const res = await fetch('{{ route("service.git-merge") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ project, to_branch: toBranch, from_branch: fromBranch }),
                });
                const data = await res.json();
                if (res.ok) {
                    result.className = 'text-sm text-green-600';
                    result.textContent = data.message;
                } else {
                    result.className = 'text-sm text-red-600';
                    result.textContent = data.message || data.error || 'Ошибка.';
                }
            } catch (err) {
                result.className = 'text-sm text-red-600';
                result.textContent = 'Сетевая ошибка.';
            } finally {
                btn.disabled = false;
            }
        });

        // ───────────────── Redis Command Widget ─────────────────

        function updateRedisPreview() {
            const site    = document.getElementById('rc-site').value.trim();
            const command = document.getElementById('rc-command').value.trim();
            const params  = document.getElementById('rc-params').value.trim();

            let payload = {};
            if (command) payload.action = command;

            if (params) {
                try {
                    const parsed = JSON.parse(params);
                    if (typeof parsed === 'object' && !Array.isArray(parsed)) {
                        Object.assign(payload, parsed);
                    } else {
                        payload.parameters = params;
                    }
                } catch {
                    payload.parameters = params;
                }
            }

            if (command) payload.requested_at = '<now>';

            const key = site ? `${site}/commands` : '{сайт}/commands';
            document.getElementById('rc-preview').textContent =
                `key: ${key}\n\n` + JSON.stringify(payload, null, 2);
        }

        ['rc-site', 'rc-command', 'rc-params'].forEach(id => {
            document.getElementById(id).addEventListener('input', updateRedisPreview);
        });
        updateRedisPreview();

        document.getElementById('redis-form').addEventListener('submit', async function (e) {
            e.preventDefault();
            const site    = document.getElementById('rc-site').value.trim();
            const command = document.getElementById('rc-command').value.trim();
            const params  = document.getElementById('rc-params').value.trim();
            const btn     = document.getElementById('rc-submit');
            const result  = document.getElementById('rc-result');

            if (!site || !command) {
                result.className = 'text-sm text-red-600';
                result.textContent = 'Укажите сайт и команду.';
                return;
            }

            btn.disabled = true;
            result.className = 'text-sm text-gray-500';
            result.textContent = 'Отправка…';

            try {
                const res = await fetch('{{ route("service.redis-command") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ site, command, parameters: params }),
                });
                const data = await res.json();
                if (res.ok) {
                    result.className = 'text-sm text-green-600';
                    result.textContent = data.message;
                } else {
                    result.className = 'text-sm text-red-600';
                    result.textContent = data.message || data.error || 'Ошибка.';
                }
            } catch (err) {
                result.className = 'text-sm text-red-600';
                result.textContent = 'Сетевая ошибка.';
            } finally {
                btn.disabled = false;
            }
        });
    </script>
</x-app-layout>
