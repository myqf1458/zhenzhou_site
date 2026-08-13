/**
 * 悬浮音乐播放器插件
 * 使用方式：在任意 HTML 页面底部添加
 *   <script src="music_player.js"></script>
 * 需要配合 music_api.php 提供播放列表数据
 */
(function () {
    'use strict';

    // ===== 自动加载 Font Awesome（如果页面未引入）=====
    if (!document.querySelector('link[href*="font-awesome"]') && !document.querySelector('link[href*="fontawesome"]')) {
        var faLink = document.createElement('link');
        faLink.rel = 'stylesheet';
        faLink.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';
        document.head.appendChild(faLink);
    }

    // ===== 注入 CSS =====
    var css = `
    @keyframes mpRotate { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }
    @keyframes mpSlideIn { from{opacity:0;transform:translateY(20px) scale(0.9)} to{opacity:1;transform:translateY(0) scale(1)} }
    @keyframes mpBarPulse { 0%,100%{height:6px} 50%{height:14px} }
    @keyframes mpVinylSpin { from{transform:rotate(0deg)} to{transform:rotate(360deg)} }

    #mpTrigger {
        position: fixed; right: 24px; bottom: 24px;
        width: 52px; height: 52px; border-radius: 50%;
        background: linear-gradient(135deg, #c9a9d4, #e8c5d0);
        box-shadow: 0 4px 20px rgba(201,169,212,0.4);
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; z-index: 998; transition: all 0.3s ease; user-select: none;
    }
    #mpTrigger:hover { transform: scale(1.1); box-shadow: 0 6px 28px rgba(201,169,212,0.5); }
    #mpTrigger i { font-size: 20px; color: #fff; }
    #mpTrigger .mp-bars { display: none; align-items: flex-end; gap: 2px; height: 16px; }
    #mpTrigger .mp-bars span { width: 3px; background: #fff; border-radius: 2px; animation: mpBarPulse 0.6s ease-in-out infinite; }
    #mpTrigger .mp-bars span:nth-child(2) { animation-delay: 0.15s; }
    #mpTrigger .mp-bars span:nth-child(3) { animation-delay: 0.3s; }
    #mpTrigger.playing .mp-icon { display: none; }
    #mpTrigger.playing .mp-bars { display: flex; }

    #mpPanel {
        position: fixed; right: 24px; bottom: 88px; width: 320px;
        background: rgba(255,255,255,0.72);
        backdrop-filter: blur(20px) saturate(180%); -webkit-backdrop-filter: blur(20px) saturate(180%);
        border: 1px solid rgba(255,255,255,0.5); border-radius: 20px;
        box-shadow: 0 12px 48px rgba(201,169,212,0.25);
        z-index: 999; overflow: hidden; animation: mpSlideIn 0.3s ease;
        display: none; font-family: "Noto Serif SC","PingFang SC", system-ui, sans-serif;
    }
    #mpPanel.show { display: block; }
    #mpPanel * { box-sizing: border-box; }

    .mp-header { display: flex; gap: 14px; padding: 16px; background: linear-gradient(135deg, rgba(201,169,212,0.12), rgba(232,197,208,0.12)); }
    .mp-cover-wrap { position: relative; width: 64px; height: 64px; flex-shrink: 0; }
    .mp-cover { width: 64px; height: 64px; border-radius: 50%; object-fit: cover; background: linear-gradient(135deg, #c9a9d4, #e8c5d0); box-shadow: 0 2px 12px rgba(201,169,212,0.3); }
    .mp-cover-wrap.playing .mp-cover { animation: mpVinylSpin 8s linear infinite; }
    .mp-cover-wrap::after { content:''; position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:12px; height:12px; border-radius:50%; background:#fff; box-shadow:0 0 0 2px rgba(201,169,212,0.3); }
    .mp-info { flex:1; min-width:0; display:flex; flex-direction:column; justify-content:center; gap:4px; }
    .mp-title { font-size:14px; font-weight:600; color:#4a4556; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .mp-artist { font-size:12px; color:#8b8594; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    .mp-progress-section { padding: 0 16px 8px; }
    .mp-progress-bar { height:4px; background:rgba(201,169,212,0.2); border-radius:2px; cursor:pointer; position:relative; }
    .mp-progress-filled { height:100%; background:linear-gradient(90deg, #c9a9d4, #e8c5d0); border-radius:2px; width:0%; position:relative; transition:width 0.1s linear; }
    .mp-progress-filled::after { content:''; position:absolute; right:-5px; top:50%; transform:translateY(-50%); width:10px; height:10px; border-radius:50%; background:#fff; box-shadow:0 1px 4px rgba(201,169,212,0.4); opacity:0; transition:opacity 0.2s; }
    .mp-progress-bar:hover .mp-progress-filled::after { opacity:1; }
    .mp-time-row { display:flex; justify-content:space-between; margin-top:4px; font-size:11px; color:#b5b0bc; }

    .mp-controls { display:flex; align-items:center; justify-content:center; gap:16px; padding:8px 0 12px; }
    .mp-btn { width:36px; height:36px; border-radius:50%; border:none; background:rgba(201,169,212,0.1); color:#8b8594; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:14px; transition:all 0.2s ease; }
    .mp-btn:hover { background:rgba(201,169,212,0.2); color:#c9a9d4; transform:translateY(-1px); }
    .mp-btn-play { width:44px; height:44px; background:linear-gradient(135deg, #c9a9d4, #e8c5d0); color:#fff; font-size:16px; box-shadow:0 4px 12px rgba(201,169,212,0.3); }
    .mp-btn-play:hover { background:linear-gradient(135deg, #b892c2, #d8b5c0); color:#fff; box-shadow:0 6px 20px rgba(201,169,212,0.4); }
    .mp-btn.active { color:#c9a9d4; background:rgba(201,169,212,0.2); }

    .mp-volume-row { display:flex; align-items:center; gap:8px; padding:0 16px 12px; }
    .mp-volume-row i { font-size:13px; color:#b5b0bc; cursor:pointer; width:16px; text-align:center; }
    .mp-volume-bar { flex:1; height:3px; background:rgba(201,169,212,0.2); border-radius:2px; cursor:pointer; position:relative; }
    .mp-volume-filled { height:100%; background:rgba(201,169,212,0.5); border-radius:2px; width:80%; }

    .mp-playlist-toggle { text-align:center; padding:8px; border-top:1px solid rgba(201,169,212,0.15); cursor:pointer; font-size:12px; color:#8b8594; transition:all 0.2s; user-select:none; }
    .mp-playlist-toggle:hover { color:#c9a9d4; background:rgba(201,169,212,0.05); }
    .mp-playlist-toggle i { margin-left:4px; transition:transform 0.3s; }
    .mp-playlist-toggle.open i { transform:rotate(180deg); }

    .mp-playlist { max-height:0; overflow:hidden; transition:max-height 0.3s ease; }
    .mp-playlist.open { max-height:240px; overflow-y:auto; }
    .mp-playlist::-webkit-scrollbar { width:4px; }
    .mp-playlist::-webkit-scrollbar-thumb { background:rgba(201,169,212,0.3); border-radius:2px; }

    .mp-playlist-item { display:flex; align-items:center; gap:10px; padding:8px 16px; cursor:pointer; transition:background 0.2s; }
    .mp-playlist-item:hover { background:rgba(201,169,212,0.08); }
    .mp-playlist-item.current { background:rgba(201,169,212,0.12); }
    .mp-playlist-item .pl-thumb { width:32px; height:32px; border-radius:8px; object-fit:cover; flex-shrink:0; }
    .mp-playlist-item .pl-info { flex:1; min-width:0; }
    .mp-playlist-item .pl-title { font-size:13px; color:#4a4556; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .mp-playlist-item .pl-artist { font-size:11px; color:#b5b0bc; }
    .mp-playlist-item .pl-status { font-size:12px; color:#c9a9d4; width:16px; text-align:center; }

    .mp-loading { text-align:center; padding:40px; color:#b5b0bc; font-size:13px; }
    .mp-loading i { animation:mpRotate 1s linear infinite; display:inline-block; }
    .mp-empty { text-align:center; padding:40px 20px; color:#b5b0bc; font-size:13px; }
    .mp-empty i { font-size:32px; margin-bottom:12px; display:block; opacity:0.5; }

    @media (max-width:600px) {
        #mpPanel { width:calc(100vw - 32px); right:16px; bottom:80px; }
        #mpTrigger { right:16px; bottom:16px; }
    }
    `;
    var styleEl = document.createElement('style');
    styleEl.textContent = css;
    document.head.appendChild(styleEl);

    // ===== 注入 HTML =====
    var container = document.createElement('div');
    container.innerHTML = `
        <div id="mpTrigger" title="音乐播放器">
            <i class="fas fa-music mp-icon"></i>
            <div class="mp-bars"><span></span><span></span><span></span></div>
        </div>
        <div id="mpPanel">
            <div class="mp-header">
                <div class="mp-cover-wrap" id="mpCoverWrap">
                    <img class="mp-cover" id="mpCover" src="" alt="封面">
                </div>
                <div class="mp-info">
                    <div class="mp-title" id="mpTitle">未播放</div>
                    <div class="mp-artist" id="mpArtist">--</div>
                </div>
            </div>
            <div class="mp-progress-section">
                <div class="mp-progress-bar" id="mpProgressBar">
                    <div class="mp-progress-filled" id="mpProgressFilled"></div>
                </div>
                <div class="mp-time-row">
                    <span id="mpCurrentTime">0:00</span>
                    <span id="mpDuration">0:00</span>
                </div>
            </div>
            <div class="mp-controls">
                <button class="mp-btn" id="mpPrev" title="上一首"><i class="fas fa-step-backward"></i></button>
                <button class="mp-btn mp-btn-play" id="mpPlayBtn" title="播放/暂停"><i class="fas fa-play"></i></button>
                <button class="mp-btn" id="mpNext" title="下一首"><i class="fas fa-step-forward"></i></button>
                <button class="mp-btn" id="mpModeBtn" title="播放模式"><i class="fas fa-redo"></i></button>
            </div>
            <div class="mp-volume-row">
                <i class="fas fa-volume-up" id="mpVolumeIcon"></i>
                <div class="mp-volume-bar" id="mpVolumeBar">
                    <div class="mp-volume-filled" id="mpVolumeFilled"></div>
                </div>
            </div>
            <div class="mp-playlist-toggle" id="mpPlaylistToggle">播放列表 <i class="fas fa-chevron-down"></i></div>
            <div class="mp-playlist" id="mpPlaylist">
                <div class="mp-loading"><i class="fas fa-circle-notch"></i> 加载中...</div>
            </div>
            <audio id="mpAudio" preload="metadata"></audio>
        </div>
    `;
    document.body.appendChild(container);

    // ===== 状态管理 =====
    var playlist = [];
    var currentIndex = -1;
    var isPlaying = false;
    var playMode = 'list';
    var volume = 0.8;
    var isMuted = false;
    var lastVolume = 0.8;
    var autoPlayAttempted = false;

    // ===== DOM =====
    var $ = function (id) { return document.getElementById(id); };
    var trigger = $('mpTrigger');
    var panel = $('mpPanel');
    var audio = $('mpAudio');
    var cover = $('mpCover');
    var coverWrap = $('mpCoverWrap');
    var titleEl = $('mpTitle');
    var artistEl = $('mpArtist');
    var playBtn = $('mpPlayBtn');
    var prevBtn = $('mpPrev');
    var nextBtn = $('mpNext');
    var modeBtn = $('mpModeBtn');
    var progressBar = $('mpProgressBar');
    var progressFilled = $('mpProgressFilled');
    var currentTimeEl = $('mpCurrentTime');
    var durationEl = $('mpDuration');
    var volumeBar = $('mpVolumeBar');
    var volumeFilled = $('mpVolumeFilled');
    var volumeIcon = $('mpVolumeIcon');
    var playlistToggle = $('mpPlaylistToggle');
    var playlistEl = $('mpPlaylist');

    // 封面加载失败时的默认图
    cover.onerror = function () {
        this.src = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><rect width="64" height="64" rx="32" fill="#c9a9d4"/><text x="32" y="40" font-size="28" text-anchor="middle" fill="#fff">♪</text></svg>');
    };

    // ===== 工具函数 =====
    function formatTime(sec) {
        if (isNaN(sec) || sec === 0) return '0:00';
        var m = Math.floor(sec / 60);
        var s = Math.floor(sec % 60);
        return m + ':' + (s < 10 ? '0' + s : s);
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function showToast(msg) {
        var t = document.createElement('div');
        t.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:rgba(0,0,0,0.7);color:#fff;padding:10px 24px;border-radius:12px;font-size:14px;z-index:10001;pointer-events:none;transition:opacity 0.3s;';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(function () { t.style.opacity = '0'; }, 1500);
        setTimeout(function () { t.remove(); }, 1900);
    }

    // ===== 自动播放 =====
    function tryAutoPlay() {
        if (autoPlayAttempted || playlist.length === 0) return;
        autoPlayAttempted = true;

        // 加载第一首歌曲信息
        currentIndex = 0;
        var song = playlist[0];
        audio.src = song.src;
        cover.src = song.cover;
        titleEl.textContent = song.title;
        artistEl.textContent = song.artist;
        renderPlaylist();

        // 尝试自动播放（浏览器可能阻止）
        audio.play().then(function () {
            isPlaying = true;
            updatePlayUI();
            renderPlaylist();
        }).catch(function () {
            // 自动播放被浏览器策略阻止，等待用户首次交互后触发
            isPlaying = false;
            updatePlayUI();

            function interactPlay() {
                audio.play().then(function () {
                    isPlaying = true;
                    updatePlayUI();
                    renderPlaylist();
                }).catch(function () { });
                document.removeEventListener('click', interactPlay);
                document.removeEventListener('touchstart', interactPlay);
                document.removeEventListener('keydown', interactPlay);
            }
            document.addEventListener('click', interactPlay);
            document.addEventListener('touchstart', interactPlay);
            document.addEventListener('keydown', interactPlay);
        });
    }

    // ===== 加载播放列表 =====
    function loadPlaylist() {
        fetch('music_api.php?action=list')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.code === 200 && res.data && res.data.length > 0) {
                    playlist = res.data;
                    renderPlaylist();
                    tryAutoPlay();
                } else {
                    playlistEl.innerHTML = '<div class="mp-empty"><i class="fas fa-music"></i>暂无音乐<br><span style="font-size:11px;">请将音频文件放在 music/ 目录下</span></div>';
                }
            })
            .catch(function () {
                playlistEl.innerHTML = '<div class="mp-empty"><i class="fas fa-exclamation-circle"></i>加载失败</div>';
            });
    }

    // ===== 渲染播放列表 =====
    function renderPlaylist() {
        var html = '';
        playlist.forEach(function (song, i) {
            var isCurrent = i === currentIndex;
            html += '<div class="mp-playlist-item' + (isCurrent ? ' current' : '') + '" data-index="' + i + '">';
            html += '<img class="pl-thumb" src="' + escapeHtml(song.cover) + '" alt="">';
            html += '<div class="pl-info"><div class="pl-title">' + escapeHtml(song.title) + '</div><div class="pl-artist">' + escapeHtml(song.artist) + '</div></div>';
            html += '<div class="pl-status">';
            if (isCurrent && isPlaying) html += '<i class="fas fa-volume-up"></i>';
            else if (isCurrent) html += '<i class="fas fa-pause"></i>';
            html += '</div></div>';
        });
        playlistEl.innerHTML = html;

        playlistEl.querySelectorAll('.mp-playlist-item').forEach(function (item) {
            item.addEventListener('click', function () {
                playByIndex(parseInt(this.dataset.index));
            });
        });
    }

    // ===== 播放控制 =====
    function playByIndex(index) {
        if (index < 0 || index >= playlist.length) return;
        currentIndex = index;
        var song = playlist[index];
        audio.src = song.src;
        cover.src = song.cover;
        titleEl.textContent = song.title;
        artistEl.textContent = song.artist;
        audio.play().then(function () {
            isPlaying = true;
            updatePlayUI();
            renderPlaylist();
        }).catch(function () {
            showToast('音频加载失败，请检查文件是否存在');
            isPlaying = false;
            updatePlayUI();
        });
    }

    function togglePlay() {
        if (currentIndex === -1) {
            if (playlist.length > 0) playByIndex(0);
            else showToast('播放列表为空');
            return;
        }
        if (isPlaying) {
            audio.pause();
            isPlaying = false;
        } else {
            audio.play().then(function () { isPlaying = true; }).catch(function () { showToast('播放失败'); });
            isPlaying = true;
        }
        updatePlayUI();
        renderPlaylist();
    }

    function playNext() {
        if (playlist.length === 0) return;
        var next;
        if (playMode === 'random') {
            do { next = Math.floor(Math.random() * playlist.length); }
            while (next === currentIndex && playlist.length > 1);
        } else {
            next = (currentIndex + 1) % playlist.length;
        }
        playByIndex(next);
    }

    function playPrev() {
        if (playlist.length === 0) return;
        var prev;
        if (playMode === 'random') {
            do { prev = Math.floor(Math.random() * playlist.length); }
            while (prev === currentIndex && playlist.length > 1);
        } else {
            prev = currentIndex <= 0 ? playlist.length - 1 : currentIndex - 1;
        }
        playByIndex(prev);
    }

    function updatePlayUI() {
        playBtn.innerHTML = isPlaying ? '<i class="fas fa-pause"></i>' : '<i class="fas fa-play"></i>';
        trigger.classList.toggle('playing', isPlaying);
        coverWrap.classList.toggle('playing', isPlaying);
    }

    function updateModeUI() {
        var icons = { list: 'fa-redo', single: 'fa-redo-alt', random: 'fa-random' };
        var titles = { list: '列表循环', single: '单曲循环', random: '随机播放' };
        modeBtn.querySelector('i').className = 'fas ' + icons[playMode];
        modeBtn.title = titles[playMode];
        modeBtn.classList.toggle('active', playMode !== 'list');
    }

    function updateVolumeIcon() {
        var cls = 'fas ';
        if (volume === 0 || isMuted) cls += 'fa-volume-mute';
        else if (volume < 0.5) cls += 'fa-volume-down';
        else cls += 'fa-volume-up';
        volumeIcon.className = cls;
    }

    // ===== 事件绑定 =====
    trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.classList.toggle('show');
    });

    document.addEventListener('click', function (e) {
        if (!panel.contains(e.target) && !trigger.contains(e.target)) {
            panel.classList.remove('show');
        }
    });

    playBtn.addEventListener('click', togglePlay);
    prevBtn.addEventListener('click', playPrev);
    nextBtn.addEventListener('click', playNext);

    modeBtn.addEventListener('click', function () {
        var modes = ['list', 'single', 'random'];
        playMode = modes[(modes.indexOf(playMode) + 1) % modes.length];
        updateModeUI();
        showToast({ list: '列表循环', single: '单曲循环', random: '随机播放' }[playMode]);
    });

    audio.addEventListener('timeupdate', function () {
        if (audio.duration) {
            progressFilled.style.width = (audio.currentTime / audio.duration * 100) + '%';
            currentTimeEl.textContent = formatTime(audio.currentTime);
        }
    });

    audio.addEventListener('loadedmetadata', function () {
        durationEl.textContent = formatTime(audio.duration);
    });

    audio.addEventListener('ended', function () {
        if (playMode === 'single') {
            audio.currentTime = 0;
            audio.play();
        } else {
            playNext();
        }
    });

    audio.addEventListener('error', function () {
        isPlaying = false;
        updatePlayUI();
    });

    // 进度条拖动
    var isDraggingProgress = false;
    function seekTo(e) {
        var rect = progressBar.getBoundingClientRect();
        var pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        if (audio.duration) {
            audio.currentTime = pct * audio.duration;
            progressFilled.style.width = (pct * 100) + '%';
        }
    }
    progressBar.addEventListener('mousedown', function (e) { isDraggingProgress = true; seekTo(e); });
    document.addEventListener('mousemove', function (e) { if (isDraggingProgress) seekTo(e); });
    document.addEventListener('mouseup', function () { isDraggingProgress = false; });
    progressBar.addEventListener('touchstart', function (e) { seekTo({ clientX: e.touches[0].clientX }); });

    // 音量控制
    var isDraggingVolume = false;
    function setVolume(e) {
        var rect = volumeBar.getBoundingClientRect();
        var pct = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        volume = pct;
        audio.volume = volume;
        volumeFilled.style.width = (pct * 100) + '%';
        updateVolumeIcon();
    }
    volumeBar.addEventListener('mousedown', function (e) { isDraggingVolume = true; setVolume(e); });
    document.addEventListener('mousemove', function (e) { if (isDraggingVolume) setVolume(e); });
    document.addEventListener('mouseup', function () { isDraggingVolume = false; });
    volumeBar.addEventListener('touchstart', function (e) { setVolume({ clientX: e.touches[0].clientX }); });

    volumeIcon.addEventListener('click', function () {
        if (isMuted) {
            isMuted = false;
            audio.volume = lastVolume;
            volumeFilled.style.width = (lastVolume * 100) + '%';
        } else {
            lastVolume = volume;
            isMuted = true;
            audio.volume = 0;
            volumeFilled.style.width = '0%';
        }
        updateVolumeIcon();
    });

    playlistToggle.addEventListener('click', function () {
        playlistEl.classList.toggle('open');
        playlistToggle.classList.toggle('open');
    });

    document.addEventListener('keydown', function (e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
        if (e.code === 'Space' && panel.classList.contains('show')) {
            e.preventDefault();
            togglePlay();
        }
    });

    // ===== 初始化 =====
    audio.volume = volume;
    volumeFilled.style.width = (volume * 100) + '%';
    updateVolumeIcon();
    updateModeUI();
    loadPlaylist();
})();
