(function () {
    'use strict';
    const messages = {
        401: 'سجّل الدخول مجددًا ثم استأنف الرفع.', 403: 'ليس لديك صلاحية رفع هذا الملف.',
        404: 'الرفع غير متاح لهذا الحساب. أزل الملف وأعد اختياره.', 409: 'تعارض في الرفع. استأنف بالملف الأصلي أو أزله لبدء رفع جديد.', 410: 'انتهت صلاحية الرفع. أزل الملف وأعد اختياره.',
        413: 'الخادم يرفض حجم جزء الرفع. تواصل مع الإدارة.', 419: 'انتهت الجلسة. حدّث الصفحة ثم اختر الملف الأصلي للاستئناف.',
        422: 'لم يجتز الملف التحقق. راجع نوع الملف وحجمه.', 429: 'طلبات كثيرة. انتظر قليلًا ثم استأنف.',
        500: 'حدث خطأ في خادم الرفع. حاول الاستئناف لاحقًا أو تواصل مع الإدارة.', 502: 'خادم الرفع غير متاح مؤقتًا. حاول الاستئناف لاحقًا.',
        503: 'خدمة الرفع أو التحقق من الفيديو غير متاحة مؤقتًا. حاول الاستئناف لاحقًا أو تواصل مع الإدارة.',
        504: 'انتهت مهلة الخادم. يمكنك استئناف الرفع.', 507: 'مساحة التخزين غير كافية. تواصل مع الإدارة.',
    };
    const setupMessages = {
        UPLOAD_DATABASE_SCHEMA: 'خدمة الرفع تحتاج إكمال تحديث الموقع.',
        UPLOAD_DATABASE_PERMISSION: 'تعذر وصول خدمة الرفع إلى بياناتها على الخادم.',
        UPLOAD_STORAGE_UNAVAILABLE: 'تعذر حفظ الملف في مساحة تخزين الموقع.',
        UPLOAD_VIDEO_SERVICE_UNAVAILABLE: 'خدمة فحص الفيديو غير جاهزة على الخادم.',
        UPLOAD_SERVER_ERROR: 'حدث خطأ داخلي في خدمة الرفع.',
    };
    class LessonResumableUpload {
        constructor(options) { Object.assign(this, options); this.stopped = false; this.state = null; }
        request(method, suffix, body, progress) {
            return new Promise((resolve, reject) => {
                const xhr = new XMLHttpRequest(); this.xhr = xhr;
                // Completion may use the configured 300-second verification ceiling plus request overhead.
                xhr.open(method, this.base + suffix); xhr.timeout = suffix.endsWith('/complete') ? 360000 : 180000;
                xhr.setRequestHeader('Accept', 'application/json'); xhr.setRequestHeader('X-CSRF-TOKEN', this.csrf);
                if (body && !(body instanceof FormData)) { xhr.setRequestHeader('Content-Type', 'application/json'); body = JSON.stringify(body); }
                xhr.upload.onprogress = e => { if (e.lengthComputable && progress) progress(e.loaded / e.total); };
                xhr.onload = () => {
                    let data; try { data = JSON.parse(xhr.responseText); } catch (_) { data = {}; }
                    if (!data || typeof data !== 'object' || Array.isArray(data)) data = {};
                    if (xhr.status >= 200 && xhr.status < 300 && data && typeof data === 'object' && !xhr.responseURL?.includes('/login')) resolve(data);
                    else {
                        const status = xhr.responseURL?.includes('/login') ? 401 : xhr.status;
                        const validation = status === 422 ? Object.values(data.errors || {}).flat()[0] : null;
                        const setup = Object.hasOwn(setupMessages, data.code) ? setupMessages[data.code] + ' رمز المشكلة: ' + data.code + '.' : null;
                        const reference = typeof data.reference === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(data.reference)
                            ? ' رقم المتابعة: ' + data.reference : '';
                        const error = new Error((validation || setup || messages[status] || 'تعذر إكمال الرفع. حاول الاستئناف.') + reference);
                        error.status = status;
                        error.retryable = data.retryable !== false;
                        error.wait = Math.min(60, Math.max(1, Number(xhr.getResponseHeader('Retry-After')) || 2)) * 1000;
                        reject(error);
                    }
                };
                xhr.onerror = xhr.ontimeout = () => { const error = new Error('انقطع الاتصال أو انتهت المهلة. يمكنك استئناف الرفع.'); error.status = 0; reject(error); };
                xhr.onabort = () => reject(new Error('تم إيقاف الرفع مؤقتًا. اضغط استئناف للمتابعة.'));
                xhr.send(body || null);
            });
        }
        async retry(operation) {
            for (let attempt = 0; ; attempt++) {
                if (this.stopped) throw new Error('تم إيقاف الرفع مؤقتًا.');
                try { return await operation(); }
                catch (error) {
                    if (this.stopped || error.retryable === false || attempt >= 4 || ![0, 408, 429, 500, 502, 503, 504].includes(error.status)) throw error;
                    const reason = error.status === 0 ? 'انقطع الاتصال أو انتهت المهلة.'
                        : error.status === 429 ? 'طلبات كثيرة. ننتظر قليلًا.'
                        : [408, 504].includes(error.status) ? 'انتهت مهلة الخادم.'
                        : 'تعذر إكمال الطلب على الخادم.';
                    this.onStatus(reason + ' إعادة المحاولة ' + (attempt + 1) + '/4…');
                    await new Promise(resolve => setTimeout(resolve, error.wait || Math.min(16000, 1000 * 2 ** attempt)));
                }
            }
        }
        async digest(blob) {
            const digest = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
            return Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('');
        }
        async run() {
            this.stopped = false;
            if (!globalThis.crypto?.subtle) throw new Error('يلزم اتصال HTTPS آمن لرفع الملفات والتحقق منها.');
            const fingerprint = await this.digest(new Blob([this.file.slice(0, 65536), this.file.slice(-65536)]));
            if (this.stopped) throw new Error('تم إيقاف الرفع مؤقتًا.');
            this.key = 'lesson-upload:' + this.user + ':' + fingerprint + ':' + this.file.size + ':' + this.file.name;
            let saved; try { saved = localStorage.getItem(this.key); } catch (_) { /* Upload still works without browser persistence. */ }
            if (!this.state && saved) {
                try { this.state = await this.retry(() => this.request('GET', '/' + saved)); }
                catch (error) { if (![404, 410].includes(error.status)) throw error; this.forget(); }
            }
            if (this.state?.status === 'attached') this.forget();
            if (!this.state || this.state.status === 'cancelled') {
                const id = crypto.randomUUID();
                try { localStorage.setItem(this.key, id); } catch (_) { /* Optional persistence. */ }
                this.state = await this.retry(() => this.request('POST', '', { name: this.file.name, size: this.file.size, upload_id: id }));
            } else this.state = await this.retry(() => this.request('GET', '/' + this.state.upload_id));
            if (this.stopped) throw new Error('تم إيقاف الرفع مؤقتًا.');
            if (!Number.isInteger(this.state.chunk_size) || this.state.chunk_size < 1 || !Number.isInteger(this.state.received_bytes)) throw new Error('استجابة رفع غير صحيحة من الخادم.');
            // A reselected file must match all completed chunks, not only its name and size.
            this.onStatus('التحقق من الأجزاء السابقة…');
            for (let index = 0; index < this.state.hashes.length; index++) {
                if (this.stopped) throw new Error('تم إيقاف الرفع مؤقتًا.');
                const start = index * this.state.chunk_size;
                if (await this.digest(this.file.slice(start, start + this.state.chunk_size)) !== this.state.hashes[index]) throw new Error('الملف المختار مختلف عن الملف الأصلي. أزله ثم ابدأ رفعًا جديدًا.');
            }
            while (this.state.received_bytes < this.file.size) {
                if (this.stopped) throw new Error('تم إيقاف الرفع مؤقتًا.');
                const offset = this.state.received_bytes;
                const chunk = this.file.slice(offset, offset + this.state.chunk_size);
                this.state = await this.retry(() => {
                    const body = new FormData(); body.append('index', String(Math.floor(offset / this.state.chunk_size))); body.append('chunk', chunk, 'chunk.bin');
                    return this.request('POST', '/' + this.state.upload_id + '/chunks', body, fraction => this.onProgress(offset + chunk.size * fraction, this.file.size));
                });
                this.onProgress(this.state.received_bytes, this.file.size);
            }
            this.onStatus('اكتمل النقل. جارٍ التحقق من سلامة الملف…');
            this.state = await this.retry(() => this.request('POST', '/' + this.state.upload_id + '/complete', {}));
            if (this.state.status !== 'ready') throw new Error('لم يؤكد الخادم اكتمال الملف. حاول الاستئناف.');
            return this.state;
        }
        pause() { this.stopped = true; this.xhr?.abort(); }
        async cancel() {
            this.pause();
            if (this.state) {
                try { await this.request('DELETE', '/' + this.state.upload_id); }
                catch (error) {
                    if (error.status === 409) {
                        const current = await this.request('GET', '/' + this.state.upload_id);
                        if (current.status !== 'attached') throw error;
                    } else if (error.status !== 404) throw error;
                }
            }
            this.forget();
        }
        forget() { try { if (this.key) localStorage.removeItem(this.key); } catch (_) { /* Optional persistence. */ } this.state = null; }
    }
    window.LessonResumableUpload = LessonResumableUpload;
}());
