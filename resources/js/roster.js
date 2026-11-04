import interact from 'interactjs';

export function registerRosterMatrix(Alpine) {
    Alpine.data('rosterMatrix', (config) => ({
        employees: config.employees || [],
        days: config.days || [],
        shifts: config.shifts || [],
        schedules: config.schedules || {},
        formationByDate: config.formationByDate || {},
        modAuditByDate: config.modAuditByDate || {},
        draftCount: Number(config.draftCount || 0),
        publishedCount: Number(config.publishedCount || 0),
        pendingDeleteCount: Number(config.pendingDeleteCount || 0),
        rosterStatus: config.rosterStatus || 'draft',
        modOnly: Boolean(config.modOnly),
        canEdit: Boolean(config.canEdit),
        month: Number(config.month || 11),
        year: Number(config.year || 2026),
        selectedDepartmentId: config.selectedDepartmentId || null,
        crossDayBadgeLabel: config.crossDayBadgeLabel || '+1h',
        i18n: config.i18n || {},

        // UI Enhancements State (Expandable 24h Day Timeline & Collapsible Staff Column)
        expandedDate: null,
        staffColumnCollapsed: false,
        savingShiftId: null,
        shiftFormFeedback: '',

        // New Master Shift Form State
        showNewShiftForm: false,
        creatingShift: false,
        newShift: {
            code: 'NEW',
            name: config.i18n?.defaultNewShiftName || 'Shift Baru',
            start_time: '09:00:00',
            end_time: '17:00:00',
            grace_period_minutes: 15,
            color_badge: '#3B82F6',
        },

        // Native HTML5 Drag-and-Drop & Mobile Touch-Drag State
        dragPayload: null,
        activeTouchShift: null,
        touchSelectedShift: null,
        touchDragPreviewEl: null,
        touchStartCoords: { x: 0, y: 0, moved: false },
        touchHoverCell: null,

        assignUrl: config.assignUrl || '/roster/assign',
        splitUrlTemplate: config.splitUrlTemplate || '/roster/__ID__/split',
        resizeUrlTemplate: config.resizeUrlTemplate || '/roster/__ID__/resize',
        restoreUrlTemplate: config.restoreUrlTemplate || '/roster/__ID__/restore',
        destroyUrlTemplate: config.destroyUrlTemplate || '/roster/__ID__',
        publishUrl: config.publishUrl || '/roster/publish',
        discardUrl: config.discardUrl || '/roster/discard',
        storeShiftUrl: config.storeShiftUrl || '/roster/shifts',
        updateShiftUrlTemplate: config.updateShiftUrlTemplate || '/roster/shifts/__ID__',
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',

        savingState: 'idle', // 'idle' | 'saving' | 'saved' | 'error'
        statusMessage: '',
        activeScheduleMenuId: null,

        turnaroundModal: {
            open: false,
            employeeId: null,
            dateStr: '',
            shiftId: null,
            isOff: false,
            restHours: 0,
            message: '',
        },

        resizeTooltip: {
            visible: false,
            x: 0,
            y: 0,
            text: '',
        },

        init() {
            this.recalculateCounts();
            this.recalculateAllModAudits();

            if (!this.canEdit) {
                return;
            }

            this.$nextTick(() => {
                this.initInteractResizing();
            });
        },

        get hasPendingChanges() {
            return this.draftCount > 0 || this.pendingDeleteCount > 0;
        },

        recalculateCounts() {
            let drafts = 0;
            let published = 0;
            let pendingDeletes = 0;
            const validDates = new Set(this.days.map((d) => d.date));

            for (const empId of Object.keys(this.schedules || {})) {
                const empDates = this.schedules[empId] || {};
                for (const dateKey of Object.keys(empDates)) {
                    if (!validDates.has(dateKey)) continue;
                    const list = this.getSchedules(empId, dateKey);
                    for (const item of list) {
                        if (!item) continue;
                        if (item.status === 'draft') drafts++;
                        else if (item.status === 'published') published++;
                        else if (item.status === 'pending_delete') pendingDeletes++;
                    }
                }
            }

            this.draftCount = drafts;
            this.publishedCount = published;
            this.pendingDeleteCount = pendingDeletes;
            this.rosterStatus = (drafts === 0 && pendingDeletes === 0 && published > 0) ? 'published' : 'draft';
        },

        recalculateModAudit(dateStr) {
            if (!dateStr) return;
            let modCount = 0;

            for (const empId of Object.keys(this.schedules || {})) {
                const daySchedules = this.getSchedules(empId, dateStr);
                for (const sched of daySchedules) {
                    if (!sched || sched.status === 'pending_delete' || sched.is_off) continue;
                    const shiftObj = this.getShiftById(sched.shift_id);
                    const isModShift =
                        sched.shift_code === 'MOD' ||
                        sched.duty_tag_code === 'MOD_ELIGIBLE' ||
                        shiftObj?.code === 'MOD';
                    if (isModShift) {
                        modCount++;
                    }
                }
            }

            this.modAuditByDate = {
                ...this.modAuditByDate,
                [dateStr]: {
                    count: modCount,
                    is_covered: modCount > 0,
                },
            };
        },

        recalculateAllModAudits() {
            for (const d of this.days) {
                this.recalculateModAudit(d.date);
            }
        },

        isModCovered(dateStr) {
            return Boolean(this.modAuditByDate?.[dateStr]?.is_covered);
        },

        getModCount(dateStr) {
            return Number(this.modAuditByDate?.[dateStr]?.count || 0);
        },

        toggleDateExpand(dateStr) {
            if (this.expandedDate === dateStr) {
                this.expandedDate = null;
            } else {
                this.expandedDate = dateStr;
            }
        },

        toggleStaffColumn() {
            this.staffColumnCollapsed = !this.staffColumnCollapsed;
        },

        get filteredEmployees() {
            if (!this.modOnly) {
                return this.employees;
            }
            return this.employees.filter((emp) => emp.is_mod_eligible);
        },

        getShiftByCode(code) {
            if (!code) return null;
            const clean = String(code).trim();
            if (clean === 'OFF') {
                return { id: null, code: 'OFF', name: 'Day Off (DP)', color_badge: '#71717A', duration_hours: 0, cross_day: false };
            }
            return this.shifts.find((s) => s.code === clean) || null;
        },

        getShiftById(id) {
            if (id === null || id === undefined || id === '') return null;
            return this.shifts.find((s) => Number(s.id) === Number(id)) || null;
        },

        getSchedules(empId, dateStr) {
            const raw = this.schedules?.[empId]?.[dateStr];
            if (!raw) return [];
            return Array.isArray(raw) ? raw : [raw];
        },

        getScheduleById(empId, dateStr, scheduleId) {
            const list = this.getSchedules(empId, dateStr);
            if (!scheduleId) return list[0] || null;
            return list.find((s) => Number(s.id) === Number(scheduleId) || String(s.uuid) === String(scheduleId)) || null;
        },

        getPrevDateStr(dateStr) {
            if (!dateStr) return '';
            const parts = dateStr.split('-').map(Number);
            if (parts.length !== 3) return '';
            const dt = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2] - 1));
            return dt.toISOString().slice(0, 10);
        },

        isCrossDaySchedule(sched) {
            if (!sched || sched.is_off) return false;
            if (sched.effective_start_time && sched.effective_end_time) {
                const startMins = this.timeStrToMinutes(sched.effective_start_time);
                const endMins = this.timeStrToMinutes(sched.effective_end_time);
                return Boolean(sched.cross_day || endMins <= startMins);
            }
            return Boolean(sched.cross_day);
        },

        /**
         * Kalkulasi durasi efektif jam kerja (mendukung shift lintas hari dengan modulus 24 jam):
         * Jika cross_day atau T_end <= T_start: Durasi = (T_end + 24) - T_start
         */
        calculateDurationHours(startTimeStr, endTimeStr, forceCrossDay = false) {
            if (!startTimeStr || !endTimeStr) return 0;
            const startHour = this.timeStrToMinutes(startTimeStr) / 60;
            const endHour = this.timeStrToMinutes(endTimeStr) / 60;
            if (forceCrossDay || endHour <= startHour) {
                return Number(((endHour + 24) - startHour).toFixed(2));
            }
            return Number((endHour - startHour).toFixed(2));
        },

        getScheduleDurationHours(sched) {
            if (!sched || sched.is_off) return 0;
            if (sched.effective_start_time && sched.effective_end_time) {
                return this.calculateDurationHours(
                    sched.effective_start_time,
                    sched.effective_end_time,
                    this.isCrossDaySchedule(sched)
                );
            }
            return Number(sched.duration_hours || 0);
        },

        findScheduleContextById(scheduleId) {
            if (scheduleId === null || scheduleId === undefined || scheduleId === '') return null;
            for (const empId of Object.keys(this.schedules || {})) {
                const empDates = this.schedules[empId] || {};
                for (const dateStr of Object.keys(empDates)) {
                    const list = this.getSchedules(empId, dateStr);
                    const existing = list.find(
                        (s) => s && (Number(s.id) === Number(scheduleId) || String(s.uuid) === String(scheduleId))
                    );
                    if (existing) {
                        return { empId: Number(empId), dateStr, existing };
                    }
                }
            }
            return null;
        },

        toggleScheduleMenu(scheduleId, event = null) {
            if (event && typeof event.stopPropagation === 'function') {
                event.stopPropagation();
            }
            if (!this.canEdit) return;
            this.activeScheduleMenuId = this.activeScheduleMenuId === scheduleId ? null : scheduleId;
        },

        getOvernightCarryovers(empId, dateStr) {
            const prevDateStr = this.getPrevDateStr(dateStr);
            if (!prevDateStr) return [];
            const prevList = this.getSchedules(empId, prevDateStr);
            return prevList.filter((sched) => this.isCrossDaySchedule(sched));
        },

        formatTimeShort(timeStr) {
            if (!timeStr) return '';
            return timeStr.slice(0, 5);
        },

        getStartHourDecimal(sched) {
            if (!sched || sched.is_off || !sched.effective_start_time) {
                return 0;
            }
            return this.timeStrToMinutes(sched.effective_start_time) / 60;
        },

        getEndHourDecimal(sched) {
            if (!sched || sched.is_off || !sched.effective_end_time) {
                return 0;
            }
            return this.timeStrToMinutes(sched.effective_end_time) / 60;
        },

        getTimelineLeftPercent(sched) {
            if (!sched || sched.is_off) {
                return 0;
            }
            const startHour = this.getStartHourDecimal(sched);
            return Number(((startHour / 24) * 100).toFixed(2));
        },

        getTimelineWidthPercent(sched) {
            if (!sched || sched.is_off) {
                return 100;
            }
            const startHour = this.getStartHourDecimal(sched);
            if (this.isCrossDaySchedule(sched)) {
                const firstDayHours = Math.max(0.5, 24 - startHour);
                return Number(((firstDayHours / 24) * 100).toFixed(2));
            }
            const computedDuration = this.calculateDurationHours(
                sched.effective_start_time,
                sched.effective_end_time,
                false
            );
            const durationHours = computedDuration > 0 ? computedDuration : Number(sched.duration_hours || 8);
            return Number(((durationHours / 24) * 100).toFixed(2));
        },

        getScheduleBarStyle(sched, dateStr) {
            const bg = sched?.status === 'pending_delete'
                ? 'rgba(239, 68, 68, 0.15)'
                : (sched?.is_off ? '#71717A' : (sched?.color_badge || '#004F9F'));

            if (this.expandedDate !== dateStr || !sched || sched.is_off) {
                return `background-color: ${bg}; left: 0%; width: 100%;`;
            }

            const left = this.getTimelineLeftPercent(sched);
            const width = this.getTimelineWidthPercent(sched);
            return `background-color: ${bg}; left: ${left}%; width: ${width}%;`;
        },

        getContinuationBarStyle(parentSched) {
            const bg = parentSched?.status === 'pending_delete'
                ? 'rgba(239, 68, 68, 0.15)'
                : (parentSched?.color_badge || '#004F9F');
            const rawEndHour = this.getEndHourDecimal(parentSched);
            const endHour = Math.max(0.5, rawEndHour === 0 ? 0.5 : rawEndHour);
            const width = Number(((endHour / 24) * 100).toFixed(2));
            return `background-color: ${bg}; left: 0%; width: ${width}%;`;
        },

        getMonthlyHours(empId) {
            const empSchedules = this.schedules?.[empId] || {};
            const validDates = new Set(this.days.map((d) => d.date));
            let total = 0;

            for (const dateStr of Object.keys(empSchedules)) {
                if (!validDates.has(dateStr)) continue;
                const list = this.getSchedules(empId, dateStr);
                for (const item of list) {
                    if (item && !item.is_off && item.status !== 'pending_delete') {
                        const dur = item.effective_start_time && item.effective_end_time
                            ? this.calculateDurationHours(item.effective_start_time, item.effective_end_time, this.isCrossDaySchedule(item))
                            : Number(item.duration_hours || 0);
                        total += dur;
                    }
                }
            }
            return Number(total.toFixed(1));
        },

        getMaxWeeklyHours(empId) {
            const weeklyTotals = {};

            for (const dayMeta of this.days) {
                const list = this.getSchedules(empId, dayMeta.date);
                for (const item of list) {
                    if (item && !item.is_off && item.status !== 'pending_delete') {
                        const weekKey = dayMeta.iso_week;
                        const dur = item.effective_start_time && item.effective_end_time
                            ? this.calculateDurationHours(item.effective_start_time, item.effective_end_time, this.isCrossDaySchedule(item))
                            : Number(item.duration_hours || 0);
                        weeklyTotals[weekKey] = (weeklyTotals[weekKey] || 0) + dur;
                    }
                }
            }

            const values = Object.values(weeklyTotals);
            return values.length > 0 ? Number(Math.max(...values).toFixed(1)) : 0;
        },

        isOvertimeWeek(empId) {
            return this.getMaxWeeklyHours(empId) > 40;
        },

        async assignShift(empId, dateStr, shiftId = null, overrideWarning = false, isOff = false) {
            if (!this.canEdit) return;

            if (!isOff && (shiftId === null || shiftId === undefined || shiftId === '' || Number.isNaN(Number(shiftId)))) {
                return;
            }

            const resolvedShiftId = isOff ? null : Number(shiftId);
            this.savingState = 'saving';

            try {
                const response = await window.axios.post(
                    this.assignUrl,
                    {
                        employee_id: Number(empId),
                        schedule_date: dateStr,
                        shift_id: resolvedShiftId,
                        status: 'draft',
                        override_warning: overrideWarning,
                        force: overrideWarning,
                    },
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                        },
                    }
                );

                if (!this.schedules[empId]) {
                    this.schedules[empId] = {};
                }
                const currentList = this.getSchedules(empId, dateStr);
                const newSched = response.data.schedule;
                const existingIdx = currentList.findIndex((s) => Number(s.id) === Number(newSched.id));
                if (existingIdx !== -1) {
                    currentList[existingIdx] = newSched;
                } else {
                    currentList.push(newSched);
                }
                this.schedules[empId][dateStr] = [...currentList];

                this.recalculateCounts();
                this.recalculateModAudit(dateStr);
                this.savingState = 'saved';
                this.statusMessage = response.data.message || this.i18n.savedDraft || '';
            } catch (error) {
                if (error?.response?.status === 422 && error?.response?.data?.requires_confirmation) {
                    this.savingState = 'idle';
                    this.turnaroundModal = {
                        open: true,
                        employeeId: Number(empId),
                        dateStr: dateStr,
                        shiftId: resolvedShiftId,
                        isOff: isOff,
                        restHours: error.response.data.rest_hours ?? 0,
                        message: error.response.data.message || this.i18n.turnaroundDefault || '',
                    };
                    return;
                }

                this.savingState = 'error';
                this.statusMessage = error?.response?.data?.message || this.i18n.saveFailed || '';
            }
        },

        async assignCell(empId, dateStr, shiftId = null, overrideWarning = false, isOff = false) {
            return this.assignShift(empId, dateStr, shiftId, overrideWarning, isOff);
        },

        confirmTurnaroundOverride() {
            if (!this.turnaroundModal.employeeId || !this.turnaroundModal.dateStr) {
                this.turnaroundModal.open = false;
                return;
            }

            const empId = this.turnaroundModal.employeeId;
            const dateStr = this.turnaroundModal.dateStr;
            const shiftId = this.turnaroundModal.shiftId;
            const isOff = this.turnaroundModal.isOff;

            this.turnaroundModal.open = false;
            this.assignShift(empId, dateStr, shiftId, true, isOff);
        },

        async removeScheduleById(empId, dateStr, scheduleId, event = null) {
            if (event) {
                event.stopPropagation();
            }
            if (!this.canEdit) return;

            const existing = this.getScheduleById(empId, dateStr, scheduleId);
            if (!existing || !existing.id) return;

            this.savingState = 'saving';
            const url = this.destroyUrlTemplate.replace('__ID__', existing.uuid || existing.id);

            try {
                const response = await window.axios.delete(url, {
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                });

                const currentList = this.getSchedules(empId, dateStr);
                if (response.data.action === 'pending_delete' && response.data.schedule) {
                    const idx = currentList.findIndex((s) => Number(s.id) === Number(existing.id));
                    if (idx !== -1) {
                        currentList[idx] = response.data.schedule;
                    }
                    this.schedules[empId][dateStr] = [...currentList];
                } else {
                    const filtered = currentList.filter((s) => Number(s.id) !== Number(existing.id));
                    if (filtered.length > 0) {
                        this.schedules[empId][dateStr] = filtered;
                    } else if (this.schedules[empId]) {
                        delete this.schedules[empId][dateStr];
                    }
                }

                this.recalculateCounts();
                this.recalculateModAudit(dateStr);
                this.savingState = 'saved';
                this.statusMessage = response.data.message || this.i18n.deleted || '';
            } catch (error) {
                this.savingState = 'error';
                this.statusMessage = error?.response?.data?.message || this.i18n.saveFailed || '';
            }
        },

        async restoreScheduleById(empId, dateStr, scheduleId, event = null) {
            if (event) {
                event.stopPropagation();
            }
            if (!this.canEdit) return;

            const existing = this.getScheduleById(empId, dateStr, scheduleId);
            if (!existing || !existing.id) return;

            this.savingState = 'saving';
            const url = this.restoreUrlTemplate.replace('__ID__', existing.uuid || existing.id);

            try {
                const response = await window.axios.patch(
                    url,
                    {},
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                        },
                    }
                );

                const currentList = this.getSchedules(empId, dateStr);
                const idx = currentList.findIndex((s) => Number(s.id) === Number(existing.id));
                if (idx !== -1 && response.data.schedule) {
                    currentList[idx] = response.data.schedule;
                    this.schedules[empId][dateStr] = [...currentList];
                }

                this.recalculateCounts();
                this.recalculateModAudit(dateStr);
                this.savingState = 'saved';
                this.statusMessage = response.data.message || this.i18n.restored || '';
            } catch (error) {
                this.savingState = 'error';
                this.statusMessage = error?.response?.data?.message || this.i18n.saveFailed || '';
            }
        },

        async resizeSchedule(empId, dateStr, scheduleId, newStartTime, newEndTime) {
            if (!this.canEdit) return;
            const existing = this.getScheduleById(empId, dateStr, scheduleId);
            if (!existing || !existing.id || existing.is_off || existing.status === 'pending_delete') return;

            this.savingState = 'saving';
            const url = this.resizeUrlTemplate.replace('__ID__', existing.uuid || existing.id);

            try {
                const response = await window.axios.patch(
                    url,
                    {
                        custom_start_time: newStartTime,
                        custom_end_time: newEndTime,
                    },
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                        },
                    }
                );

                const currentList = this.getSchedules(empId, dateStr);
                const idx = currentList.findIndex((s) => Number(s.id) === Number(existing.id));
                if (idx !== -1 && response.data.schedule) {
                    currentList[idx] = response.data.schedule;
                    this.schedules[empId][dateStr] = [...currentList];
                }

                this.recalculateCounts();
                this.recalculateModAudit(dateStr);
                this.savingState = 'saved';
                this.statusMessage = response.data.message || this.i18n.resized || '';
            } catch (error) {
                this.savingState = 'error';
                this.statusMessage = error?.response?.data?.message || this.i18n.saveFailed || '';
            }
        },

        async slideSchedule(empId, dateStr, scheduleId, newStartTime, newEndTime) {
            return this.resizeSchedule(empId, dateStr, scheduleId, newStartTime, newEndTime);
        },

        formatSlideDelta(deltaMins) {
            if (!deltaMins) return '0m';
            const sign = deltaMins > 0 ? '+' : '-';
            const abs = Math.abs(deltaMins);
            const h = Math.floor(abs / 60);
            const m = abs % 60;
            if (h > 0 && m > 0) return `${sign}${h}h ${m}m`;
            if (h > 0) return `${sign}${h}h`;
            return `${sign}${m}m`;
        },

        async splitSchedule(scheduleId, empId = null, dateStr = null, event = null) {
            if (event && typeof event.stopPropagation === 'function') {
                event.stopPropagation();
            }
            if (!this.canEdit) return;

            let resolvedEmpId = empId;
            let resolvedDateStr = dateStr;
            let existing = null;

            if (resolvedEmpId && resolvedDateStr) {
                existing = this.getScheduleById(resolvedEmpId, resolvedDateStr, scheduleId);
            } else {
                const ctx = this.findScheduleContextById(scheduleId);
                if (ctx) {
                    resolvedEmpId = ctx.empId;
                    resolvedDateStr = ctx.dateStr;
                    existing = ctx.existing;
                }
            }

            if (!existing || !existing.id || existing.is_off || existing.status === 'pending_delete') return;

            this.savingState = 'saving';
            this.activeScheduleMenuId = null;
            const url = this.splitUrlTemplate.replace('__ID__', existing.uuid || existing.id);

            try {
                const response = await window.axios.post(
                    url,
                    {},
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                        },
                    }
                );

                const currentList = [...this.getSchedules(resolvedEmpId, resolvedDateStr)];
                const idx = currentList.findIndex((s) => Number(s.id) === Number(existing.id));
                const splitSchedules = response.data.schedules || [];

                if (idx !== -1 && splitSchedules.length === 2) {
                    currentList.splice(idx, 1, splitSchedules[0], splitSchedules[1]);
                    this.schedules[resolvedEmpId][resolvedDateStr] = [...currentList];
                } else if (splitSchedules.length > 0) {
                    this.schedules[resolvedEmpId][resolvedDateStr] = splitSchedules;
                }

                this.recalculateCounts();
                this.recalculateModAudit(resolvedDateStr);
                this.savingState = 'saved';
                this.statusMessage = response.data.message || this.i18n.splitSuccess || '';
            } catch (error) {
                this.savingState = 'error';
                this.statusMessage = error?.response?.data?.message || this.i18n.saveFailed || '';
            }
        },

        openNewShiftForm() {
            this.showNewShiftForm = true;
            const suffix = this.shifts.length + 1;
            const candidateCode = this.shifts.some((s) => s.code === 'NEW') ? `NEW${suffix}` : 'NEW';
            this.newShift = {
                code: candidateCode,
                name: this.i18n?.defaultNewShiftName || 'Shift Baru',
                start_time: '09:00:00',
                end_time: '17:00:00',
                grace_period_minutes: 15,
                color_badge: '#3B82F6',
            };
        },

        async createMasterShift() {
            if (!this.canEdit || this.creatingShift) return;
            this.creatingShift = true;
            this.savingState = 'saving';

            try {
                const response = await window.axios.post(
                    this.storeShiftUrl,
                    {
                        code: String(this.newShift.code || 'NEW').trim().toUpperCase(),
                        name: String(this.newShift.name || this.i18n?.defaultNewShiftName || 'Shift Baru').trim(),
                        start_time: this.formatTimeShort(this.newShift.start_time || '09:00:00'),
                        end_time: this.formatTimeShort(this.newShift.end_time || '17:00:00'),
                        grace_period_minutes: Number(this.newShift.grace_period_minutes ?? 15),
                        color_badge: this.newShift.color_badge || '#3B82F6',
                    },
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                        },
                    }
                );

                const created = response.data.shift;
                if (created) {
                    this.shifts.push(created);
                }

                this.creatingShift = false;
                this.showNewShiftForm = false;
                this.savingState = 'saved';
                this.statusMessage = response.data.message || this.i18n.shiftCreated || '';
                this.shiftFormFeedback = response.data.message || this.i18n.shiftCreated || '';
                setTimeout(() => {
                    this.shiftFormFeedback = '';
                }, 3000);
            } catch (error) {
                this.creatingShift = false;
                this.savingState = 'error';
                this.statusMessage = error?.response?.data?.message || this.i18n.saveFailed || '';
            }
        },

        async updateMasterShift(shiftItem) {
            if (!this.canEdit || !shiftItem) return;
            this.savingShiftId = shiftItem.id;
            this.savingState = 'saving';
            const url = this.updateShiftUrlTemplate.replace('__ID__', shiftItem.uuid || shiftItem.id);

            try {
                const response = await window.axios.put(
                    url,
                    {
                        name: shiftItem.name,
                        start_time: this.formatTimeShort(shiftItem.start_time),
                        end_time: this.formatTimeShort(shiftItem.end_time),
                        grace_period_minutes: Number(shiftItem.grace_period_minutes ?? 15),
                        color_badge: shiftItem.color_badge,
                    },
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                        },
                    }
                );

                const updated = response.data.shift;
                const idx = this.shifts.findIndex((s) => s.id === updated.id);
                if (idx !== -1) {
                    this.shifts[idx] = updated;
                }

                this.savingShiftId = null;
                this.savingState = 'saved';
                this.statusMessage = response.data.message || this.i18n.shiftUpdated || '';
                this.shiftFormFeedback = response.data.message || this.i18n.shiftUpdated || '';
                setTimeout(() => {
                    this.shiftFormFeedback = '';
                }, 3000);
            } catch (error) {
                this.savingShiftId = null;
                this.savingState = 'error';
                this.statusMessage = error?.response?.data?.message || this.i18n.saveFailed || '';
            }
        },

        async publishRoster() {
            if (!this.canEdit || !this.hasPendingChanges) return;
            this.savingState = 'saving';

            try {
                const response = await window.axios.post(
                    this.publishUrl,
                    {
                        month: this.month,
                        year: this.year,
                        department_id: this.selectedDepartmentId,
                    },
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                        },
                    }
                );

                for (const empId of Object.keys(this.schedules)) {
                    for (const dateKey of Object.keys(this.schedules[empId])) {
                        const list = this.getSchedules(empId, dateKey);
                        const committed = list
                            .filter((item) => item && item.status !== 'pending_delete')
                            .map((item) => ({
                                ...item,
                                status: 'published',
                                published_start_time: null,
                                published_end_time: null,
                            }));

                        if (committed.length > 0) {
                            this.schedules[empId][dateKey] = committed;
                        } else {
                            delete this.schedules[empId][dateKey];
                        }
                    }
                }

                this.recalculateCounts();
                this.recalculateAllModAudits();
                this.savingState = 'saved';
                this.statusMessage = response.data.message || this.i18n.published || '';
            } catch (error) {
                this.savingState = 'error';
                this.statusMessage = error?.response?.data?.message || this.i18n.saveFailed || '';
            }
        },

        confirmDiscardDrafts() {
            if (!this.canEdit || !this.hasPendingChanges) return;
            window.dispatchEvent(new CustomEvent('open-modal', { detail: 'discard-drafts-modal' }));
        },

        closeDiscardModal() {
            window.dispatchEvent(new CustomEvent('close-modal', { detail: 'discard-drafts-modal' }));
        },

        async discardDrafts() {
            if (!this.canEdit || !this.hasPendingChanges) return;
            this.closeDiscardModal();
            this.savingState = 'saving';

            try {
                const response = await window.axios.post(
                    this.discardUrl,
                    {
                        month: this.month,
                        year: this.year,
                        department_id: this.selectedDepartmentId,
                    },
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                        },
                    }
                );

                for (const empId of Object.keys(this.schedules)) {
                    for (const dateKey of Object.keys(this.schedules[empId])) {
                        const list = this.getSchedules(empId, dateKey);
                        const restored = [];

                        for (const item of list) {
                            if (!item) continue;

                            if (item.status === 'draft') {
                                // Jika jadwal draft berasal dari jadwal published yang di-resize, kembalikan ke ukuran published sebelumnya
                                if (item.published_start_time && item.published_end_time) {
                                    const origStart = item.published_start_time;
                                    const origEnd = item.published_end_time;
                                    const startMins = this.timeStrToMinutes(origStart);
                                    const endMins = this.timeStrToMinutes(origEnd);
                                    const isCrossDay = endMins <= startMins;
                                    const durationHours = this.calculateDurationHours(origStart, origEnd, isCrossDay);

                                    restored.push({
                                        ...item,
                                        status: 'published',
                                        custom_start_time: origStart,
                                        custom_end_time: origEnd,
                                        effective_start_time: origStart,
                                        effective_end_time: origEnd,
                                        published_start_time: null,
                                        published_end_time: null,
                                        cross_day: isCrossDay,
                                        duration_hours: durationHours,
                                    });
                                }
                                continue;
                            }

                            if (item.status === 'pending_delete') {
                                if (item.published_start_time && item.published_end_time) {
                                    const origStart = item.published_start_time;
                                    const origEnd = item.published_end_time;
                                    const startMins = this.timeStrToMinutes(origStart);
                                    const endMins = this.timeStrToMinutes(origEnd);
                                    const isCrossDay = endMins <= startMins;
                                    const durationHours = this.calculateDurationHours(origStart, origEnd, isCrossDay);

                                    restored.push({
                                        ...item,
                                        status: 'published',
                                        custom_start_time: origStart,
                                        custom_end_time: origEnd,
                                        effective_start_time: origStart,
                                        effective_end_time: origEnd,
                                        published_start_time: null,
                                        published_end_time: null,
                                        cross_day: isCrossDay,
                                        duration_hours: durationHours,
                                    });
                                } else {
                                    restored.push({
                                        ...item,
                                        status: 'published',
                                    });
                                }
                                continue;
                            }

                            restored.push(item);
                        }

                        if (restored.length > 0) {
                            this.schedules[empId][dateKey] = restored;
                        } else {
                            delete this.schedules[empId][dateKey];
                        }
                    }
                }

                this.recalculateCounts();
                this.recalculateAllModAudits();
                this.savingState = 'saved';
                this.statusMessage = response.data.message || this.i18n.discarded || '';
            } catch (error) {
                this.savingState = 'error';
                this.statusMessage = error?.response?.data?.message || this.i18n.saveFailed || '';
            }
        },

        /**
         * Mobile Touch-Drag & Touch-Tap Assignment Engine
         */
        isTouchSelected(shiftId, isOff = false) {
            if (!this.touchSelectedShift) return false;
            if (isOff) return Boolean(this.touchSelectedShift.is_off);
            return !this.touchSelectedShift.is_off && Number(this.touchSelectedShift.id) === Number(shiftId);
        },

        removeTouchDragPreview() {
            if (this.touchDragPreviewEl && this.touchDragPreviewEl.parentNode) {
                this.touchDragPreviewEl.parentNode.removeChild(this.touchDragPreviewEl);
            }
            this.touchDragPreviewEl = null;
        },

        handleTouchStart(event, shift) {
            if (!this.canEdit) return;
            const touch = event.touches?.[0];
            if (!touch) return;

            const isOff = Boolean(shift?.is_off || shift?.isOff || shift?.code === 'OFF');
            const rawId = shift?.id ?? shift?.shift_id ?? null;
            const resolvedId = isOff ? null : (rawId !== null && rawId !== '' ? Number(rawId) : null);
            const resolvedCode = shift?.code || (isOff ? 'OFF' : '');
            const resolvedColor = shift?.color_badge || (isOff ? '#52525B' : '#004F9F');

            this.activeTouchShift = {
                id: resolvedId,
                shift_id: resolvedId,
                code: resolvedCode,
                color_badge: resolvedColor,
                is_off: isOff,
            };

            this.touchStartCoords = {
                x: touch.clientX,
                y: touch.clientY,
                moved: false,
            };

            this.removeTouchDragPreview();
            const ghost = document.createElement('div');
            ghost.className =
                'fixed top-0 left-0 z-tooltip pointer-events-none px-3 py-1.5 rounded-lg text-white text-xs font-bold shadow-2xl ring-2 ring-white/80 flex items-center gap-1.5';
            ghost.style.backgroundColor = resolvedColor;
            ghost.style.transform = `translate3d(${touch.clientX - 28}px, ${touch.clientY - 42}px, 0) scale(1.08)`;
            ghost.textContent = resolvedCode;
            document.body.appendChild(ghost);
            this.touchDragPreviewEl = ghost;
        },

        handleTouchMove(event) {
            if (!this.canEdit || !this.activeTouchShift) return;
            if (event.cancelable) {
                event.preventDefault();
            }

            const touch = event.touches?.[0];
            if (!touch) return;

            const dx = touch.clientX - this.touchStartCoords.x;
            const dy = touch.clientY - this.touchStartCoords.y;
            if (Math.hypot(dx, dy) > 8) {
                this.touchStartCoords.moved = true;
            }

            if (this.touchDragPreviewEl) {
                this.touchDragPreviewEl.style.transform = `translate3d(${touch.clientX - 28}px, ${touch.clientY - 42}px, 0) scale(1.08)`;
                this.touchDragPreviewEl.style.display = 'none';
            }

            const hoverTarget = document
                .elementFromPoint(touch.clientX, touch.clientY)
                ?.closest('[data-employee-id][data-date]');

            if (this.touchDragPreviewEl) {
                this.touchDragPreviewEl.style.display = '';
            }

            if (this.touchHoverCell && this.touchHoverCell !== hoverTarget) {
                this.touchHoverCell.classList.remove('ring-2', 'ring-accent', 'bg-accent/10');
            }
            if (hoverTarget) {
                hoverTarget.classList.add('ring-2', 'ring-accent', 'bg-accent/10');
            }
            this.touchHoverCell = hoverTarget || null;
        },

        handleTouchEnd(event) {
            if (!this.canEdit || !this.activeTouchShift) return;
            const touch = event.changedTouches?.[0];

            if (this.touchHoverCell) {
                this.touchHoverCell.classList.remove('ring-2', 'ring-accent', 'bg-accent/10');
                this.touchHoverCell = null;
            }

            if (this.touchDragPreviewEl) {
                this.touchDragPreviewEl.style.display = 'none';
            }

            if (touch) {
                const dropTarget = document
                    .elementFromPoint(touch.clientX, touch.clientY)
                    ?.closest('[data-employee-id][data-date]');

                if (dropTarget && this.touchStartCoords.moved) {
                    const employeeId = Number(dropTarget.dataset.employeeId);
                    const dateStr = dropTarget.dataset.date;
                    if (employeeId && dateStr) {
                        this.assignShift(
                            employeeId,
                            dateStr,
                            this.activeTouchShift.is_off ? null : this.activeTouchShift.id,
                            false,
                            Boolean(this.activeTouchShift.is_off)
                        );
                        this.touchSelectedShift = null;
                    }
                } else if (!this.touchStartCoords.moved) {
                    // Opsi Touch-Tap Alternative untuk layar sentuh mobile
                    const isSame =
                        this.touchSelectedShift &&
                        this.touchSelectedShift.code === this.activeTouchShift.code &&
                        Boolean(this.touchSelectedShift.is_off) === Boolean(this.activeTouchShift.is_off);
                    this.touchSelectedShift = isSame ? null : { ...this.activeTouchShift };
                }
            }

            this.removeTouchDragPreview();
            this.activeTouchShift = null;
        },

        handleCellTouchEnd(event, empId, dateStr) {
            if (!this.canEdit || !this.touchSelectedShift) return;
            if (event.target?.closest('button, .o-resize-handle-left, .o-resize-handle-right')) {
                return;
            }
            this.assignShift(
                empId,
                dateStr,
                this.touchSelectedShift.is_off ? null : this.touchSelectedShift.id,
                false,
                Boolean(this.touchSelectedShift.is_off)
            );
        },

        /**
         * Native HTML5 Drag-and-Drop Handlers
         */
        dragStartShift(event, shiftObj) {
            const el = event.currentTarget;
            const isOff = Boolean(
                shiftObj?.is_off ||
                shiftObj?.isOff ||
                shiftObj?.code === 'OFF' ||
                el?.dataset?.isOff === 'true' ||
                el?.dataset?.shiftCode === 'OFF'
            );
            const rawId = shiftObj?.shift_id ?? shiftObj?.id ?? el?.dataset?.shiftId ?? '';
            const resolvedShiftId = isOff ? null : (rawId !== '' && rawId !== null ? Number(rawId) : null);
            const resolvedCode = shiftObj?.code || el?.dataset?.shiftCode || (isOff ? 'OFF' : '');

            this.dragPayload = {
                shift_id: resolvedShiftId,
                shiftId: resolvedShiftId,
                code: resolvedCode,
                shiftCode: resolvedCode,
                is_off: isOff,
                isOff: isOff,
            };

            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'copy';
                event.dataTransfer.setData('text/plain', isOff ? 'OFF' : String(resolvedShiftId ?? ''));
                event.dataTransfer.setData('application/json', JSON.stringify(this.dragPayload));
            }
        },

        handlePaletteDragStart(event, shiftId, shiftCode, isOff = false) {
            this.dragStartShift(event, { id: shiftId, code: shiftCode, is_off: isOff });
        },

        dragOverCell(event) {
            if (!this.canEdit) return;
            event.preventDefault();
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'copy';
            }
            event.currentTarget.classList.add('ring-2', 'ring-accent', 'bg-accent/10');
        },

        handleCellDragOver(event) {
            this.dragOverCell(event);
        },

        dragLeaveCell(event) {
            event.currentTarget.classList.remove('ring-2', 'ring-accent', 'bg-accent/10');
        },

        handleCellDragLeave(event) {
            this.dragLeaveCell(event);
        },

        dropOnCell(event, empId, dateStr) {
            if (!this.canEdit) return;
            event.preventDefault();
            event.currentTarget.classList.remove('ring-2', 'ring-accent', 'bg-accent/10');

            let shiftId = null;
            let isOff = false;

            const rawJson = event.dataTransfer?.getData('application/json');
            if (rawJson) {
                try {
                    const parsed = JSON.parse(rawJson);
                    isOff = Boolean(parsed.is_off ?? parsed.isOff ?? parsed.code === 'OFF');
                    const parsedId = parsed.shift_id ?? parsed.shiftId ?? parsed.id;
                    shiftId = isOff ? null : (parsedId ? Number(parsedId) : null);
                } catch (_) {}
            }

            if (!isOff && !shiftId) {
                const rawText = (event.dataTransfer?.getData('text/plain') || '').trim();
                if (rawText === 'OFF') {
                    isOff = true;
                    shiftId = null;
                } else if (rawText !== '' && !Number.isNaN(Number(rawText))) {
                    shiftId = Number(rawText);
                } else if (rawText !== '') {
                    const byCode = this.getShiftByCode(rawText);
                    if (byCode && byCode.id) {
                        shiftId = Number(byCode.id);
                    }
                }
            }

            if (!isOff && !shiftId && this.dragPayload) {
                if (this.dragPayload.is_off || this.dragPayload.isOff) {
                    isOff = true;
                    shiftId = null;
                } else if (this.dragPayload.shift_id || this.dragPayload.shiftId) {
                    shiftId = Number(this.dragPayload.shift_id || this.dragPayload.shiftId);
                }
            }

            this.dragPayload = null;

            if (!isOff && (!shiftId || Number.isNaN(shiftId))) {
                return;
            }

            this.assignShift(empId, dateStr, isOff ? null : shiftId, false, isOff);
        },

        handleCellDrop(event, empId, dateStr) {
            this.dropOnCell(event, empId, dateStr);
        },

        minutesToTimeStr(totalMinutes) {
            const normalized = ((totalMinutes % 1440) + 1440) % 1440;
            const hours = Math.floor(normalized / 60);
            const mins = normalized % 60;
            return `${String(hours).padStart(2, '0')}:${String(mins).padStart(2, '0')}:00`;
        },

        timeStrToMinutes(timeStr) {
            if (!timeStr) return 420;
            const parts = timeStr.split(':').map(Number);
            return ((parts[0] || 0) * 60 + (parts[1] || 0)) % 1440;
        },

        /**
         * Mesin Resize Interaktif (InteractJS) dengan Dukungan Shift Lintas Hari (Cross-Day / Overnight)
         */
        initInteractResizing() {
            const self = this;

            interact('.o-shift-pill-resizable').resizable({
                edges: {
                    left: '.o-resize-handle-left',
                    right: '.o-resize-handle-right',
                },
                listeners: {
                    start(event) {
                        const target = event.target;
                        const empId = Number(target.dataset.employeeId);
                        const dateStr = target.dataset.date;
                        const scheduleId = Number(target.dataset.scheduleId);
                        const sched = self.getScheduleById(empId, dateStr, scheduleId);
                        if (!sched || sched.is_off || sched.status === 'pending_delete') return;

                        if (sched.status === 'published' && !sched.published_start_time && !sched.published_end_time) {
                            sched.published_start_time = sched.effective_start_time;
                            sched.published_end_time = sched.effective_end_time;
                        }

                        target.dataset.origStartMins = String(self.timeStrToMinutes(sched.effective_start_time));
                        target.dataset.origEndMins = String(self.timeStrToMinutes(sched.effective_end_time));
                        target.dataset.origCrossDay = self.isCrossDaySchedule(sched) ? '1' : '0';
                        target.dataset.accDxLeft = '0';
                        target.dataset.accDxRight = '0';
                    },
                    move(event) {
                        const target = event.target;
                        const empId = Number(target.dataset.employeeId);
                        const dateStr = target.dataset.date;
                        const scheduleId = Number(target.dataset.scheduleId);
                        const sched = self.getScheduleById(empId, dateStr, scheduleId);
                        if (!sched || sched.is_off || sched.status === 'pending_delete') return;

                        const origStart = Number(target.dataset.origStartMins || 420);
                        const origEnd = Number(target.dataset.origEndMins || 900);
                        const wasCrossDay = target.dataset.origCrossDay === '1';

                        let accLeft = Number(target.dataset.accDxLeft || 0) + event.deltaRect.left;
                        let accRight = Number(target.dataset.accDxRight || 0) + event.deltaRect.right;
                        target.dataset.accDxLeft = String(accLeft);
                        target.dataset.accDxRight = String(accRight);

                        // Snapping per 15 menit (6px per 15m)
                        const stepLeftMins = Math.round(accLeft / 6) * 15;
                        const stepRightMins = Math.round(accRight / 6) * 15;

                        const newStartMins = (((origStart + stepLeftMins) % 1440) + 1440) % 1440;
                        const newEndMins = (((origEnd + stepRightMins) % 1440) + 1440) % 1440;

                        const newStartStr = self.minutesToTimeStr(newStartMins);
                        const newEndStr = self.minutesToTimeStr(newEndMins);

                        const isCrossDay = newEndMins <= newStartMins || (wasCrossDay && newEndMins < newStartMins);
                        const durationHours = self.calculateDurationHours(newStartStr, newEndStr, isCrossDay);

                        // Perbarui pratinjau balok secara live selama handle ditarik
                        sched.effective_start_time = newStartStr;
                        sched.effective_end_time = newEndStr;
                        sched.cross_day = isCrossDay;
                        sched.duration_hours = durationHours;

                        target.dataset.previewStart = newStartStr;
                        target.dataset.previewEnd = newEndStr;

                        const crossSuffix = isCrossDay ? ` (${self.crossDayBadgeLabel})` : '';
                        self.resizeTooltip = {
                            visible: true,
                            x: event.client.x,
                            y: event.client.y - 36,
                            text: `${newStartStr.slice(0, 5)} → ${newEndStr.slice(0, 5)}${crossSuffix}`,
                        };
                    },
                    end(event) {
                        const target = event.target;
                        self.resizeTooltip.visible = false;

                        const empId = Number(target.dataset.employeeId);
                        const dateStr = target.dataset.date;
                        const scheduleId = Number(target.dataset.scheduleId);
                        const newStart = target.dataset.previewStart;
                        const newEnd = target.dataset.previewEnd;

                        delete target.dataset.previewStart;
                        delete target.dataset.previewEnd;

                        if (empId && dateStr && scheduleId && newStart && newEnd) {
                            self.resizeSchedule(empId, dateStr, scheduleId, newStart, newEnd);
                        }
                    },
                },
            }).draggable({
                lockAxis: 'x',
                ignoreFrom: '.o-resize-handle-left, .o-resize-handle-right, .cursor-col-resize, button',
                listeners: {
                    start(event) {
                        const target = event.target;
                        const empId = Number(target.dataset.employeeId);
                        const dateStr = target.dataset.date;
                        const scheduleId = Number(target.dataset.scheduleId);
                        const sched = self.getScheduleById(empId, dateStr, scheduleId);
                        if (!sched || sched.is_off || sched.status === 'pending_delete') return;

                        if (sched.status === 'published' && !sched.published_start_time && !sched.published_end_time) {
                            sched.published_start_time = sched.effective_start_time;
                            sched.published_end_time = sched.effective_end_time;
                        }

                        const origStartMins = self.timeStrToMinutes(sched.effective_start_time);
                        const origEndMins = self.timeStrToMinutes(sched.effective_end_time);
                        const wasCrossDay = self.isCrossDaySchedule(sched);
                        const origDurationMins = (wasCrossDay || origEndMins <= origStartMins)
                            ? ((origEndMins + 1440) - origStartMins)
                            : (origEndMins - origStartMins);

                        target.dataset.slideOrigStartMins = String(origStartMins);
                        target.dataset.slideOrigEndMins = String(origEndMins);
                        target.dataset.slideOrigDurationMins = String(origDurationMins);
                        target.dataset.slideOrigStartStr = (sched.effective_start_time || '').slice(0, 5);
                        target.dataset.slideOrigEndStr = (sched.effective_end_time || '').slice(0, 5);
                        target.dataset.slideAccDx = '0';
                        target.dataset.slideMoved = '0';
                    },
                    move(event) {
                        const target = event.target;
                        const empId = Number(target.dataset.employeeId);
                        const dateStr = target.dataset.date;
                        const scheduleId = Number(target.dataset.scheduleId);
                        const sched = self.getScheduleById(empId, dateStr, scheduleId);
                        if (!sched || sched.is_off || sched.status === 'pending_delete') return;

                        const origStartMins = Number(target.dataset.slideOrigStartMins || 420);
                        const origDurationMins = Number(target.dataset.slideOrigDurationMins || 480);

                        const accDx = Number(target.dataset.slideAccDx || 0) + event.dx;
                        target.dataset.slideAccDx = String(accDx);

                        // Snapping per 15 menit (6px per 15m), menjaga durasi bersih konstan
                        const deltaMins = Math.round(accDx / 6) * 15;
                        const newStartMins = (((origStartMins + deltaMins) % 1440) + 1440) % 1440;
                        const newEndMins = (((newStartMins + origDurationMins) % 1440) + 1440) % 1440;

                        const newStartStr = self.minutesToTimeStr(newStartMins);
                        const newEndStr = self.minutesToTimeStr(newEndMins);
                        const isCrossDay = (newStartMins + origDurationMins) >= 1440 || newEndMins <= newStartMins;
                        const durationHours = Number((origDurationMins / 60).toFixed(2));

                        sched.effective_start_time = newStartStr;
                        sched.effective_end_time = newEndStr;
                        sched.cross_day = isCrossDay;
                        sched.duration_hours = durationHours;

                        target.dataset.slidePreviewStart = newStartStr;
                        target.dataset.slidePreviewEnd = newEndStr;
                        target.dataset.slideMoved = deltaMins !== 0 ? '1' : '0';

                        const origStartShort = target.dataset.slideOrigStartStr || newStartStr.slice(0, 5);
                        const origEndShort = target.dataset.slideOrigEndStr || newEndStr.slice(0, 5);
                        const deltaLabel = self.formatSlideDelta(deltaMins);

                        self.resizeTooltip = {
                            visible: true,
                            x: event.client.x,
                            y: event.client.y - 36,
                            text: `${origStartShort} – ${origEndShort} → ${newStartStr.slice(0, 5)} – ${newEndStr.slice(0, 5)} (${deltaLabel})`,
                        };
                    },
                    end(event) {
                        const target = event.target;
                        self.resizeTooltip.visible = false;

                        const empId = Number(target.dataset.employeeId);
                        const dateStr = target.dataset.date;
                        const scheduleId = Number(target.dataset.scheduleId);
                        const newStart = target.dataset.slidePreviewStart;
                        const newEnd = target.dataset.slidePreviewEnd;
                        const moved = target.dataset.slideMoved === '1';

                        delete target.dataset.slideOrigStartMins;
                        delete target.dataset.slideOrigEndMins;
                        delete target.dataset.slideOrigDurationMins;
                        delete target.dataset.slideOrigStartStr;
                        delete target.dataset.slideOrigEndStr;
                        delete target.dataset.slideAccDx;
                        delete target.dataset.slidePreviewStart;
                        delete target.dataset.slidePreviewEnd;
                        delete target.dataset.slideMoved;

                        if (moved) {
                            target.dataset.justSlid = '1';
                            setTimeout(() => {
                                delete target.dataset.justSlid;
                            }, 120);
                        }

                        if (moved && empId && dateStr && scheduleId && newStart && newEnd) {
                            self.slideSchedule(empId, dateStr, scheduleId, newStart, newEnd);
                        }
                    },
                },
            });
        },
    }));
}
