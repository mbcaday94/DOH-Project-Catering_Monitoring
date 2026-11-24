@if (session('dashboard_saved'))
    <p class="dashboard-status" role="status">{{ session('dashboard_saved') }}</p>
@endif

<div class="dashboard-overview">
    <section class="records-panel dashboard-card" aria-labelledby="profile-heading">
        <div class="dashboard-card-header">
            <h2 id="profile-heading">User Profile</h2>
            <button class="dashboard-action" type="button" data-open-dialog="dashboard-profile" aria-haspopup="dialog">Edit profile</button>
        </div>
        <div class="dashboard-profile-content">
            @if ($profile->profile_picture_path)
                <img class="dashboard-avatar" src="{{ route('dashboard.profile.picture') }}" width="88" height="88" alt="Profile picture">
            @else
                <span class="dashboard-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($profile->name, 0, 1)) }}</span>
            @endif
            <dl class="dashboard-profile">
                <div><dt>Name</dt><dd>{{ $profile->name }}</dd></div>
                <div><dt>Designation</dt><dd>{{ $profile->designation ?? '—' }}</dd></div>
                <div><dt>Work Status</dt><dd>{{ $profile->work_status ?? '—' }}</dd></div>
            </dl>
        </div>
    </section>

    <section class="records-panel dashboard-card" aria-labelledby="upcoming-heading">
        <div class="dashboard-card-header">
            <h2 id="upcoming-heading">Upcoming Events</h2>
        </div>
        @forelse ($upcoming as $event)
            @if ($loop->first)
                <ul class="dashboard-events">
            @endif
            <li>
                <time datetime="{{ $event->starts_on }}">{{ \Carbon\CarbonImmutable::parse($event->starts_on)->format('M j, Y') }}</time>
                <strong>{{ $event->title }}</strong>
                <span>{{ ucfirst($event->category) }} · {{ $event->starts_at ? substr($event->starts_at, 0, 5) : 'All day' }}</span>
            </li>
            @if ($loop->last)
                </ul>
            @endif
        @empty
            <p class="dashboard-empty">No upcoming events.</p>
        @endforelse
    </section>

    <section class="records-panel dashboard-card dashboard-calendar" aria-labelledby="calendar-heading">
        <div class="dashboard-card-header">
            <div>
                <h2 id="calendar-heading">Calendar</h2>
                <p>My personal and work schedule</p>
            </div>
            <button class="dashboard-action" type="button" data-open-dialog="dashboard-schedule" aria-haspopup="dialog">+ Add schedule entry</button>
        </div>
        <nav class="calendar-month" aria-label="Calendar month">
            <a class="dashboard-action" href="{{ route('dashboard', ['view' => 'overview', 'month' => $month->subMonth()->format('Y-m')]) }}" aria-label="Previous month">‹</a>
            <strong id="calendar-month-title">{{ $month->format('F Y') }}</strong>
            <a class="dashboard-action" href="{{ route('dashboard', ['view' => 'overview', 'month' => $month->addMonth()->format('Y-m')]) }}" aria-label="Next month">›</a>
        </nav>
        <div class="calendar-scroll" tabindex="0" role="region" aria-labelledby="calendar-month-title">
            <div class="calendar-grid">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                    <div class="calendar-weekday">{{ $weekday }}</div>
                @endforeach
                @for ($blank = 1; $blank < $month->dayOfWeekIso; $blank++)
                    <div class="calendar-day calendar-day-empty" aria-hidden="true"></div>
                @endfor
                @for ($day = 1; $day <= $month->daysInMonth; $day++)
                    @php($date = $month->day($day)->toDateString())
                    <div @class(['calendar-day', 'calendar-today' => $date === $today])>
                        <time datetime="{{ $date }}" @if ($date === $today) aria-current="date" @endif>{{ $day }}</time>
                        @foreach ($calendarEntries->get($date, []) as $event)
                            <div @class(['calendar-event', 'calendar-event-personal' => $event->category === 'personal'])>
                                <strong>{{ $event->title }}</strong>
                                <small>{{ ucfirst($event->category) }}@if ($event->starts_at) · {{ substr($event->starts_at, 0, 5) }}@endif</small>
                            </div>
                        @endforeach
                    </div>
                @endfor
            </div>
        </div>
    </section>
</div>

@php($profileErrors = $errors->getBag('dashboardProfile'))
<dialog class="record-dialog po-entry-dialog" id="dashboard-profile" aria-labelledby="profile-dialog-title" @if ($profileErrors->any()) data-auto-open @endif>
    <div class="record-dialog-header">
        <h2 id="profile-dialog-title">Edit profile</h2>
        <form method="dialog"><button type="submit">Close</button></form>
    </div>
    <form class="po-entry-form" method="post" action="{{ route('dashboard.profile.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PATCH')
        @foreach (['name' => 'Name', 'designation' => 'Designation', 'work_status' => 'Work Status'] as $field => $label)
            <div class="po-entry-field">
                <label for="profile-{{ $field }}">{{ $label }}</label>
                <input id="profile-{{ $field }}" name="{{ $field }}" value="{{ $profileErrors->any() ? old($field) : $profile->{$field} }}" maxlength="255" @required($field === 'name')
                    @error($field, 'dashboardProfile') aria-invalid="true" aria-describedby="profile-error-{{ $field }}" @enderror>
                @error($field, 'dashboardProfile')
                    <p class="error" id="profile-error-{{ $field }}">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
        <div class="po-entry-field">
            <label for="profile-picture">Profile picture</label>
            <input id="profile-picture" name="profile_picture" type="file" accept=".jpg,.jpeg,.png,.webp"
                aria-describedby="profile-picture-help @error('profile_picture', 'dashboardProfile') profile-picture-error @enderror"
                @error('profile_picture', 'dashboardProfile') aria-invalid="true" @enderror>
            <small id="profile-picture-help">JPG, PNG, or WebP up to 2 MB.</small>
            @error('profile_picture', 'dashboardProfile')
                <p class="error" id="profile-picture-error">{{ $message }}</p>
            @enderror
        </div>
        <button type="submit" class="primary">Save profile</button>
    </form>
</dialog>

@php($scheduleErrors = $errors->getBag('dashboardSchedule'))
<dialog class="record-dialog po-entry-dialog" id="dashboard-schedule" aria-labelledby="schedule-dialog-title" @if ($scheduleErrors->any()) data-auto-open @endif>
    <div class="record-dialog-header">
        <h2 id="schedule-dialog-title">Add schedule entry</h2>
        <form method="dialog"><button type="submit">Close</button></form>
    </div>
    <form class="po-entry-form" method="post" action="{{ route('dashboard.schedule.store') }}">
        @csrf
        @foreach (['title' => ['Event name', 'text'], 'category' => ['Category', 'select'], 'starts_on' => ['Date', 'date'], 'starts_at' => ['Time (optional)', 'time']] as $field => [$label, $type])
            <div class="po-entry-field">
                <label for="schedule-{{ $field }}">{{ $label }}</label>
                @if ($type === 'select')
                    <select id="schedule-{{ $field }}" name="{{ $field }}" required
                        @error($field, 'dashboardSchedule') aria-invalid="true" aria-describedby="schedule-error-{{ $field }}" @enderror>
                        <option value="work" @selected(old($field) === 'work')>Work</option>
                        <option value="personal" @selected(old($field) === 'personal')>Personal</option>
                    </select>
                @else
                    <input id="schedule-{{ $field }}" name="{{ $field }}" type="{{ $type }}" value="{{ old($field, $type === 'date' ? $today : null) }}"
                        @if ($type === 'text') maxlength="255" @endif @required($type !== 'time')
                        @error($field, 'dashboardSchedule') aria-invalid="true" aria-describedby="schedule-error-{{ $field }}" @enderror>
                @endif
                @error($field, 'dashboardSchedule')
                    <p class="error" id="schedule-error-{{ $field }}">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
        <button type="submit" class="primary">Save schedule entry</button>
    </form>
</dialog>
