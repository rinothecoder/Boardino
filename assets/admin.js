/* global BoardinoConfig, jQuery */
(() => {
  'use strict';
  const cfg = window.BoardinoConfig;
  const main = document.getElementById('bi-main');
  const layer = document.getElementById('bi-layer');
  if (!cfg || !main) return;
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const initials = name => name.trim().split(/\s+/).slice(0, 2).map(s => s[0]).join('').toUpperCase();
  const avatarRefresh = String(Date.now());
  function avatarHTML(name, url, label = name, decorative = false) {
    let image = '';
    try {
      if (url) {
        const source = new URL(url, window.location.href);
        if (['http:', 'https:'].includes(source.protocol)) {
          // Retry Gravatar once per page load, without altering signed local-avatar URLs.
          if ((source.hostname === 'gravatar.com' || source.hostname.endsWith('.gravatar.com')) && source.pathname.startsWith('/avatar/')) source.searchParams.set('bi_refresh', avatarRefresh);
          image = `<img class="bi-avatar-image" src="${esc(source.href)}" alt="" aria-hidden="true" width="28" height="28" decoding="async">`;
        }
      }
    } catch (_) { /* Keep initials when the provider does not return a usable URL. */ }
    return `<span class="bi-avatar" role="img" title="${esc(name)}" ${decorative ? 'aria-hidden="true"' : `aria-label="${esc(label)}"`}>${esc(initials(name))}${image}</span>`;
  }
  document.getElementById('boardino').addEventListener('error', event => {
    if (event.target.matches?.('.bi-avatar-image')) event.target.remove();
  }, true);
  const iconNames = {'plus-alt2':'plus','no-alt':'x','editor-alignleft':'align-left','admin-comments':'message-circle','format-image':'image','arrow-up-alt2':'chevron-up','arrow-down-alt2':'chevron-down'};
  const icon = (name,text = '') => `<svg class="bi-icon" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${window.BoardinoIcons[iconNames[name] || name] || ''}</svg>${text}`;
  const options = (items, selected, empty) => (empty !== undefined ? `<option value="">${esc(empty)}</option>` : '') + items.map(i => `<option value="${esc(i.id)}" ${String(i.id) === String(selected) ? 'selected' : ''}>${esc(i.name)}</option>`).join('');
  let board, panel = null, settingsData = null, saving = false, dragging = false, noticeTimer, lastFocus, mediaFrame = null, mediaOpen = false, imageFocus = null;
  const modalLayer = document.createElement('div'); modalLayer.id = 'bi-modal-layer'; document.getElementById('boardino').appendChild(modalLayer);
  const filters = {search: '', category: '', person: '', archived: false};
  let columnDraft = null;
  const namedColors = {purple:'#7c3aed',blue:'#2563eb',green:'#16a34a',orange:'#ea580c',rose:'#e11d48',gray:'#666666'};
  const colorHex = value => {
    const raw = namedColors[value] || String(value || '#7c3aed');
    if(/^#[0-9a-f]{3}$/i.test(raw)) return '#'+raw.slice(1).split('').map(c => c+c).join('').toLowerCase();
    return /^#[0-9a-f]{6}$/i.test(raw) ? raw.toLowerCase() : '#7c3aed';
  };
  const colorRgb = hex => [1,3,5].map(i => parseInt(colorHex(hex).slice(i,i+2),16));
  const colorTone = (color,factor,base = 0) => '#'+colorRgb(color).map(n => Math.round(n*factor+base*(1-factor)).toString(16).padStart(2,'0')).join('');
  const badgeStyle = color => `background:${colorTone(color,.12,255)};color:${colorTone(color,.35)};border-color:${colorTone(color,.25,255)}`;
  const colorControl = (value,label) => `<div class="bi-color-control"><input class="bi-color-value" type="hidden" value="${colorHex(value)}"><button type="button" class="bi-color-button" data-action="color-picker" aria-label="${esc(label)}" title="${esc(label)}" aria-expanded="false"><span class="bi-color-dot" style="background:${colorHex(value)}"></span></button></div>`;
  function colorHSV(hex) {
    const [r,g,b] = colorRgb(hex).map(n => n/255), max = Math.max(r,g,b), min = Math.min(r,g,b), delta = max-min;
    let h = 0;
    if(delta) h = (max === r ? ((g-b)/delta)%6 : max === g ? (b-r)/delta+2 : (r-g)/delta+4)*60;
    return {h:(h+360)%360,s:max ? delta/max : 0,v:max};
  }
  function hsvHex({h,s,v}) {
    const c = v*s, x = c*(1-Math.abs((h/60)%2-1)), m = v-c;
    const rgb = h<60 ? [c,x,0] : h<120 ? [x,c,0] : h<180 ? [0,c,x] : h<240 ? [0,x,c] : h<300 ? [x,0,c] : [c,0,x];
    return '#'+rgb.map(n => Math.round((n+m)*255).toString(16).padStart(2,'0')).join('');
  }
  let colorPicker = null;
  function closeColorPicker() {
    if(!colorPicker) return;
    colorPicker.control.querySelector('button').setAttribute('aria-expanded','false');
    colorPicker.popup.remove(); colorPicker = null;
  }
  function updatePicker(hex,hsv = colorHSV(hex)) {
    if(!colorPicker) return;
    const {control,popup} = colorPicker; colorPicker.hsv = hsv;
    control.querySelector('.bi-color-value').value = hex;
    control.querySelector('.bi-color-dot').style.background = hex;
    const area = popup.querySelector('.bi-color-area'); area.style.setProperty('--bi-hue',hsv.h);
    area.setAttribute('aria-valuenow',Math.round(hsv.s*100));
    area.setAttribute('aria-valuetext',`${hex}, saturation ${Math.round(hsv.s*100)}%, brightness ${Math.round(hsv.v*100)}%`);
    const knob = popup.querySelector('.bi-color-knob'); knob.style.left = `${hsv.s*100}%`; knob.style.top = `${(1-hsv.v)*100}%`;
    popup.querySelector('.bi-color-hue').value = Math.round(hsv.h); popup.querySelector('.bi-color-hex').value = hex;
    popup.querySelector('.bi-color-hex').setAttribute('aria-invalid','false');
  }
  function openColorPicker(button) {
    if(saving) return;
    if(colorPicker?.control === button.parentElement) { closeColorPicker(); return; }
    closeColorPicker(); const control = button.parentElement, hex = control.querySelector('.bi-color-value').value;
    const popup = document.createElement('div'); popup.className = 'bi-color-popup'; popup.setAttribute('role','dialog'); popup.setAttribute('aria-label','Choose category color');
    popup.innerHTML = `<div class="bi-color-area" tabindex="0" role="slider" aria-label="Saturation and brightness" aria-valuemin="0" aria-valuemax="100" aria-valuenow="100"><span class="bi-color-knob"></span></div><label class="screen-reader-text">Hue</label><input class="bi-color-hue" aria-label="Hue" type="range" min="0" max="359"><div class="bi-color-footer"><label>Hex<input class="bi-color-hex" aria-label="Hex color" type="text" maxlength="7" spellcheck="false"></label><button type="button" class="bi-action-icon" data-action="color-done" aria-label="Use this color" title="Use this color">${icon('check')}</button></div>`;
    control.appendChild(popup); colorPicker = {control,popup,hsv:colorHSV(hex)}; updatePicker(hex); button.setAttribute('aria-expanded','true');
    const area = popup.querySelector('.bi-color-area');
    const point = event => { const rect = area.getBoundingClientRect(), hsv = {...colorPicker.hsv,s:Math.max(0,Math.min(1,(event.clientX-rect.left)/rect.width)),v:1-Math.max(0,Math.min(1,(event.clientY-rect.top)/rect.height))}; updatePicker(hsvHex(hsv),hsv); };
    area.addEventListener('pointerdown',event => { event.preventDefault(); area.setPointerCapture(event.pointerId); point(event); area.focus(); });
    area.addEventListener('pointermove',event => { if(area.hasPointerCapture(event.pointerId)) point(event); });
    area.addEventListener('keydown',event => {
      if(!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key)) return;
      event.preventDefault(); const hsv = {...colorPicker.hsv}, step = event.shiftKey ? .1 : .01;
      if(event.key === 'ArrowLeft') hsv.s = Math.max(0,hsv.s-step); if(event.key === 'ArrowRight') hsv.s = Math.min(1,hsv.s+step);
      if(event.key === 'ArrowUp') hsv.v = Math.min(1,hsv.v+step); if(event.key === 'ArrowDown') hsv.v = Math.max(0,hsv.v-step);
      updatePicker(hsvHex(hsv),hsv);
    });
    popup.querySelector('.bi-color-hue').addEventListener('input',event => { const hsv = {...colorPicker.hsv,h:Number(event.target.value)}; updatePicker(hsvHex(hsv),hsv); });
    popup.querySelector('.bi-color-hex').addEventListener('input',event => { const value = event.target.value; if(/^#[0-9a-f]{6}$/i.test(value)) updatePicker(value.toLowerCase()); else event.target.setAttribute('aria-invalid','true'); });
    popup.scrollIntoView({block:'nearest'}); popup.querySelector('.bi-color-hex').focus();
  }

  async function api(path, {method = 'GET', body} = {}) {
    const headers = {'X-WP-Nonce': cfg.nonce};
    if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
    const response = await fetch(cfg.api + path, {method, headers, cache: 'no-store', credentials: 'same-origin', body: body instanceof FormData ? body : body ? JSON.stringify(body) : undefined});
    let result;
    try { result = await response.json(); } catch (_) { throw new Error('The server could not respond. Please try again.'); }
    if (!response.ok) { const error = new Error(result.message || 'This change could not be saved.'); error.status = response.status; throw error; }
    return result;
  }
  function toast(message, error = false) {
    const el = document.getElementById('bi-notice');
    clearTimeout(noticeTimer); el.textContent = message; el.classList.toggle('bi-toast--error', error); el.hidden = false;
    noticeTimer = setTimeout(() => { el.hidden = true; }, error ? 10000 : 4000);
  }
  function errorIn(id, message) {
    const el = document.getElementById(id); if (el) { el.textContent = message; el.hidden = false; el.focus(); }
  }
  function shown(card) {
    return card.archived === filters.archived && (!filters.category || card.category === filters.category) && (!filters.person || (filters.person === 'unassigned' ? !card.assignees.length : card.assignees.includes(Number(filters.person)))) && (!filters.search || `${card.title} ${card.description}`.toLowerCase().includes(filters.search.toLowerCase()));
  }
  function cardHTML(card) {
    const category = board.settings.categories.find(c => c.id === card.category);
    return `<article class="bi-card" data-id="${card.id}"><button type="button" class="bi-card-open" data-action="open" data-id="${card.id}">
      <span class="bi-card-title-row"><span class="bi-card-title" title="${esc(card.title)}">${esc(card.title)}</span></span>
      ${card.description ? `<span class="bi-card-excerpt">${esc(card.description.slice(0, 110))}${card.description.length > 110 ? '…' : ''}</span>` : ''}
      <span class="bi-card-meta"><span class="bi-card-people-category"><span class="bi-card-avatars">${card.assigneeUsers.map(person => avatarHTML(person.name,person.avatarUrl,'Assigned to '+person.name)).join('')}</span>${category ? `<span class="bi-tag" style="${badgeStyle(category.color)}" title="${esc(category.name)}">${esc(category.name)}</span>` : ''}</span><span class="bi-card-counts">${card.comments ? `<span title="${card.comments} comments">${icon('admin-comments')} ${card.comments}<span class="screen-reader-text"> comments</span></span>` : ''}</span></span>
    </button></article>`;
  }
  function columnComposerHTML() {
    if (!cfg.admin || filters.archived) return '';
    if (!columnDraft) return `<aside id="bi-add-column-slot" class="bi-add-column-slot"><button type="button" class="bi-button bi-button--quiet bi-add-column-button" data-action="column-open" ${board.settings.columns.length >= 30 ? 'disabled title="You can have up to 30 columns."' : ''}>${icon('plus','Add column')}</button></aside>`;
    return `<aside id="bi-add-column-slot" class="bi-add-column-slot"><form id="bi-column-form" class="bi-column-composer" novalidate><div class="bi-column-composer-heading"><h3>New column</h3><button type="button" class="bi-icon-button" data-action="column-close" aria-label="Close new column">${icon('x')}</button></div><label for="bi-column-name">Column name</label><input id="bi-column-name" name="name" type="text" value="${esc(columnDraft.name)}" maxlength="60" placeholder="For example: Review" autocomplete="off"><div id="bi-column-error" class="bi-error" role="alert" tabindex="-1" ${columnDraft.error ? '' : 'hidden'}>${esc(columnDraft.error)}</div><button type="submit" class="bi-button" ${columnDraft.name.trim() ? '' : 'disabled'}>${icon('plus','Add column')}</button></form></aside>`;
  }
  function openColumnComposer() {
    if (saving || !cfg.admin || board.settings.columns.length >= 30) return;
    columnDraft = {name:'',error:''};
    const slot = document.getElementById('bi-add-column-slot');
    slot.outerHTML = columnComposerHTML();
    document.getElementById('bi-column-name').focus();
  }
  function closeColumnComposer() {
    if (saving) return;
    columnDraft = null;
    document.getElementById('bi-add-column-slot').outerHTML = columnComposerHTML();
    document.querySelector('[data-action="column-open"]')?.focus();
  }
  async function addColumn(form) {
    if (saving || !cfg.admin || !columnDraft) return;
    columnDraft.name = form.elements.name.value;
    const name = columnDraft.name.trim();
    if (!name) { columnDraft.error = 'Give your column a name.'; errorIn('bi-column-error',columnDraft.error); return; }
    saving = true; columnDraft.error = '';
    document.getElementById('bi-column-error').hidden = true;
    setBusy(form,true,'Adding…');
    jQuery('.bi-card-list.ui-sortable').sortable('disable');
    try {
      const result = await api('columns',{method:'POST',body:{name,revision:board.revision}});
      board.settings.columns.push(result.column); board.revision = result.revision;
      columnDraft = null; renderBoard();
      const heading = [...main.querySelectorAll('.bi-column')].find(el => el.dataset.column === result.column.id)?.querySelector('h3');
      if (heading) { heading.tabIndex = -1; heading.focus(); }
      toast('Column added.');
    } catch (e) {
      columnDraft.error = e.message;
      if (e.status === 409) {
        try { await loadBoard(); } catch (_) { /* Preserve the draft if the connection is unavailable. */ }
      }
      errorIn('bi-column-error',columnDraft.error);
    } finally {
      saving = false;
      if (form.isConnected) setBusy(form,false);
      if (!filters.archived && !filters.search && !filters.category && !filters.person) jQuery('.bi-card-list.ui-sortable').sortable('enable');
    }
  }
  function renderBoard() {
    const count = board.cards.filter(shown).length;
    main.className = 'bi-main';
    main.innerHTML = `<div class="bi-heading"><div><h2>${esc(board.settings.name)}</h2><p>${filters.archived ? 'Archived cards are kept here until you restore them.' : 'Keep your tasks and conversations in one place.'}</p></div>${!filters.archived ? `<button class="bi-button" data-action="new" data-column="${esc(board.settings.columns[0].id)}">${icon('plus-alt2', 'Add card')}</button>` : ''}</div>
      <div class="bi-toolbar"><div class="bi-search">${icon('search')}<input id="bi-search" type="search" value="${esc(filters.search)}" placeholder="Search cards…" aria-label="Search cards"></div><label class="screen-reader-text" for="bi-filter-category">Filter by category</label><select id="bi-filter-category">${options(board.settings.categories, filters.category, 'All categories')}</select><label class="screen-reader-text" for="bi-filter-person">Filter by person</label><select id="bi-filter-person"><option value="">Everyone</option><option value="unassigned" ${filters.person === 'unassigned' ? 'selected' : ''}>Unassigned</option>${options(board.users, filters.person)}</select><label class="screen-reader-text" for="bi-filter-view">Card view</label><select id="bi-filter-view"><option value="active" ${!filters.archived ? 'selected' : ''}>Active cards</option><option value="archive" ${filters.archived ? 'selected' : ''}>Archived cards</option></select>${filters.search || filters.category || filters.person ? '<button class="bi-text-button" data-action="clear-filters">Clear filters</button>' : ''}<span id="bi-count" class="bi-total">${count} ${count === 1 ? 'card' : 'cards'}</span></div>
      <div class="bi-board" aria-label="${esc(board.settings.name)}">${board.settings.columns.map(col => {
        const cards = board.cards.filter(c => c.column === col.id && shown(c));
        return `<section class="bi-column" data-column="${esc(col.id)}" aria-label="${esc(col.name)}"><div class="bi-column-heading"><h3>${esc(col.name)}</h3><span class="bi-column-count">${cards.length}</span></div><div class="bi-card-list" data-column="${esc(col.id)}">${cards.map(cardHTML).join('')}</div><p class="bi-column-empty" ${cards.length ? 'hidden' : ''}>${filters.archived ? 'No archived cards' : (filters.search || filters.category || filters.person) ? 'No matching cards' : 'Nothing here yet'}</p>${!filters.archived ? `<button class="bi-add-card" data-action="new" data-column="${esc(col.id)}">${icon('plus-alt2', 'Add card')}</button>` : ''}</section>`;
      }).join('')}${columnComposerHTML()}</div>${filters.search || filters.category || filters.person ? '<p class="bi-board-help">Clear filters to move cards.</p>' : ''}`;
    installSorting();
  }
  function installSorting() {
    if (filters.archived || filters.search || filters.category || filters.person) return;
    jQuery('.bi-card-list').sortable({cancel: '', connectWith: '.bi-card-list', items: '> .bi-card', placeholder: 'bi-card-placeholder', forcePlaceholderSize: true, tolerance: 'pointer', distance: 8, scroll: true,
      start: (_, ui) => { dragging = true; ui.placeholder.height(ui.item.outerHeight()); main.classList.add('bi-dragging'); },
      stop: async (_, ui) => {
        dragging = false; main.classList.remove('bi-dragging'); saving = true;
        jQuery('.bi-card-list').sortable('disable');
        const parent = ui.item.parent();
        try { await api('move', {method: 'POST', body: {id: Number(ui.item.attr('data-id')), column: parent.attr('data-column'), order: parent.children('.bi-card').map((_, el) => Number(el.dataset.id)).get(), revision: board.revision}}); await loadBoard(); }
        catch (e) { toast(e.message, true); await loadBoard().catch(() => {}); }
        finally { saving = false; }
      }
    });
  }
  async function loadBoard(render = true) {
    board = await api('board'); if (render) renderBoard();
  }
  function panelValues() {
    const form = document.getElementById('bi-card-form');
    if (!form) return null;
    return {title: form.elements.title.value, description: form.elements.description.value, column: panel.card.column, category: form.elements.category.value, assignees: [...form.querySelectorAll('input[name="assignees"]:checked')].map(el => Number(el.value)).sort((a,b) => a-b), images: panel.taskImages.map(image => image.id)};
  }
  function cardDirty() {
    if (!panel || panel.card.archived) return false;
    const current = panelValues();
    return Boolean(current && (['title','description','category'].some(key => current[key] !== panel.card[key]) || JSON.stringify(current.assignees) !== JSON.stringify([...panel.card.assignees].sort((a,b) => a-b)) || JSON.stringify(current.images) !== JSON.stringify(panel.card.images.map(image => image.id))));
  }
  function commentDirty() { return Boolean(document.getElementById('bi-comment-text')?.value.trim()) || Boolean(panel?.files.length); }
  function dirty() { return cardDirty() || commentDirty(); }
  function disposePreviews() { if (panel) panel.files.forEach(f => { if (f.preview?.startsWith('blob:')) URL.revokeObjectURL(f.preview); }); }
  async function closePanel(discardComment = false) {
    if (saving || !panel || mediaOpen || modalLayer.children.length) return;
    if (cardDirty()) {
      const form = document.getElementById('bi-card-form');
      const values = panelValues();
      if (!panel.card.id && !values.title.trim() && !values.description.trim() && !values.category && !values.assignees.length && !values.images.length) {
        // Closing an untouched new card does not create a blank record.
      } else if (!await saveCard(form)) { return; }
    }
    if (!discardComment && commentDirty()) {
      document.getElementById('bi-discard').hidden = false; document.getElementById('bi-keep-editing').focus(); return;
    }
    finishClosePanel();
  }
  function finishClosePanel() {
    if(saving) return;
    closeColorPicker(); disposePreviews(); panel = null; layer.innerHTML = ''; document.body.classList.remove('bi-panel-open');
    if(lastFocus?.isConnected) lastFocus.focus(); else main.querySelector('button')?.focus();
  }
  function todoColumn() {
    return board.settings.columns.find(c => c.id === 'todo')?.id || board.settings.columns.find(c => c.name.trim().toLowerCase() === 'to do')?.id || board.settings.columns[0].id;
  }
  function categoryBubblesHTML(selected,archived = false) {
    return board.settings.categories.map(c => `<button type="button" class="bi-category-bubble" data-action="category" data-category="${esc(c.id)}" style="${badgeStyle(c.color)}" aria-pressed="${selected === c.id}" ${archived ? 'disabled' : ''}>${selected === c.id ? icon('check') : ''}${esc(c.name)}</button>`).join('');
  }
  function categoryEditorHTML() {
    return `<div id="bi-category-editor" class="bi-category-editor" hidden><div class="bi-category-editor-heading"><h4>Edit categories</h4><button type="button" class="bi-icon-button" data-action="categories-close" aria-label="Close categories">${icon('no-alt')}</button></div><div id="bi-category-rows">${board.settings.categories.map(c => `<div class="bi-category-row" data-id="${esc(c.id)}" data-name="${esc(c.name)}" data-color="${esc(c.color || 'purple')}"><input type="text" aria-label="Category name" value="${esc(c.name)}" maxlength="60">${colorControl(c.color,'Category color for '+c.name)}<button type="button" class="bi-action-icon" data-action="category-save" aria-label="Save category" title="Save category">${icon('save')}</button></div>`).join('')}</div><div class="bi-category-row bi-category-new"><input type="text" aria-label="New category name" placeholder="New category name" maxlength="60">${colorControl('purple','New category color')}<button type="button" class="bi-action-icon" data-action="category-save" aria-label="Add category" title="Add category">${icon('plus')}</button></div><p class="bi-help">Categories are shared across this board.</p><div id="bi-category-error" class="bi-error" role="alert" tabindex="-1" hidden></div></div>`;
  }
  async function saveCategory(button) {
    if (saving) return;
    const row = button.closest('.bi-category-row'), input = row.querySelector('input'), color = row.querySelector('.bi-color-value').value;
    if (!input.value.trim()) { errorIn('bi-category-error','Give the category a name.'); input.focus(); return; }
    saving = true; button.disabled = true; document.getElementById('bi-category-error').hidden = true;
    try {
      const result = await api('categories',{method:'POST',body:{id:row.dataset.id || '',name:input.value,color,previous_name:row.dataset.name || '',previous_color:row.dataset.color || ''}});
      board.settings.categories = result.categories; board.revision = result.revision;
      const field = document.getElementById('bi-category'), chosen = row.dataset.id ? field.value : result.id;
      field.value = chosen; document.getElementById('bi-category-options').innerHTML = categoryBubblesHTML(chosen,panel.card.archived);
      closeColorPicker(); const editor = document.getElementById('bi-category-editor'); editor.outerHTML = categoryEditorHTML();
      document.getElementById('bi-category-editor').hidden = false; renderBoard(); toast(row.dataset.id ? 'Category updated.' : 'Category added.');
    } catch(e) { errorIn('bi-category-error',e.message); }
    finally { saving = false; if(button.isConnected) button.disabled = false; }
  }
  function updateCommentState() {
    const button = document.querySelector('#bi-comment-form button[type="submit"]');
    if(button) button.disabled = saving || !commentDirty();
  }
  function assigneesHTML(card) {
    return `<fieldset class="bi-field bi-field--full bi-assignee-field" ${card.archived ? 'disabled' : ''}><legend>Assigned to</legend><div class="bi-assignee-list">${board.users.map(user => `<label class="bi-member bi-assignee-option"><input type="checkbox" name="assignees" value="${user.id}" ${card.assignees.includes(user.id) ? 'checked' : ''}>${avatarHTML(user.name,user.avatarUrl,user.name,true)}<span>${esc(user.name)}</span></label>`).join('')}</div></fieldset>`;
  }
  function taskImageHTML(image, archived) {
    return `<div class="bi-task-image"><button type="button" class="bi-comment-image" data-action="image-open" data-image="${image.id}" aria-label="Open image: ${esc(image.name)}"><img src="${esc(image.thumb)}" alt="${esc(image.name)}"></button>${!archived ? `<button type="button" class="bi-image-remove" data-action="task-image-remove" data-image="${image.id}" aria-label="Remove ${esc(image.name)} from task" title="Remove from task">${icon('no-alt')}</button>` : ''}</div>`;
  }
  function taskImagesHTML(card) {
    return `<div class="bi-field"><span id="bi-task-images-label" class="bi-field-label">Image (optional)</span><div role="group" aria-labelledby="bi-task-images-label"><div id="bi-task-images" class="bi-task-images">${panel.taskImages.map(image => taskImageHTML(image,card.archived)).join('')}</div>${!card.archived ? '<div class="bi-media-actions"><button type="button" class="bi-button bi-button--quiet" data-action="task-media-upload">Upload</button><button type="button" class="bi-button bi-button--quiet" data-action="task-media-select">Select</button></div><p class="bi-help">Up to 10 images. Images are shown inside this task.</p>' : ''}</div></div>`;
  }
  function renderTaskImages() {
    const target = document.getElementById('bi-task-images');
    if (target && panel) target.innerHTML = panel.taskImages.map(image => taskImageHTML(image,panel.card.archived)).join('');
  }
  function renderPanel() {
    const card = panel.card;
    const disabled = card.archived ? 'disabled' : '';
    layer.innerHTML = `<div class="bi-backdrop" data-action="close"></div><section class="bi-drawer" role="dialog" aria-modal="true" aria-labelledby="bi-panel-heading"><header class="bi-drawer-header"><h2 id="bi-panel-heading" tabindex="-1">${card.id ? card.archived ? 'Archived card' : 'Card details' : 'New card'}</h2><div class="bi-drawer-header-actions">${card.id ? `<button type="button" class="bi-icon-button ${!card.archived ? 'bi-icon-button--archive' : ''}" data-action="archive-request" aria-label="${card.archived ? 'Restore card' : 'Archive card'}" title="${card.archived ? 'Restore card' : 'Archive card'}">${icon(card.archived ? 'archive-restore' : 'trash-2')}</button>` : ''}<button type="button" class="bi-icon-button" data-action="close" aria-label="Close card">${icon('no-alt')}</button></div></header><div class="bi-drawer-scroll">
      <div id="bi-discard" class="bi-inline-confirm" hidden><h3>Discard this comment draft?</h3><p>Your card changes are saved. This comment has not been posted.</p><div class="bi-actions"><button id="bi-keep-editing" class="bi-button bi-button--quiet" data-action="keep">Keep editing</button><button class="bi-button bi-button--danger" data-action="discard">Discard comment</button></div></div>
      <form id="bi-card-form"><div id="bi-card-error" class="bi-error" role="alert" tabindex="-1" hidden></div><div id="bi-card-save-failure" class="bi-save-failure" hidden><button type="button" class="bi-button bi-button--quiet" data-action="discard-unsaved">Discard changes and close</button><p class="bi-help">Unsaved card edits and comment drafts will be discarded.</p></div><div class="bi-field"><label for="bi-title">Title</label><input id="bi-title" name="title" class="bi-title-input" type="text" value="${esc(card.title)}" maxlength="200" required placeholder="What needs doing?" ${disabled}></div><div class="bi-field"><label for="bi-description">Description</label><textarea id="bi-description" name="description" rows="4" maxlength="20000" placeholder="Add a little context…" ${disabled}>${esc(card.description)}</textarea></div>${taskImagesHTML(card)}<div class="bi-card-fields">${assigneesHTML(card)}<div class="bi-field bi-field--full"><div class="bi-category-heading"><label id="bi-category-label">Category</label>${!card.archived ? '<button type="button" class="bi-text-button bi-category-toggle" data-action="categories">Edit categories</button>' : ''}</div><input id="bi-category" type="hidden" name="category" value="${esc(card.category)}"><div id="bi-category-options" class="bi-category-options" role="group" aria-labelledby="bi-category-label">${categoryBubblesHTML(card.category,card.archived)}</div>${!card.archived ? categoryEditorHTML() : ''}</div></div><div class="bi-actions">${!card.archived && !card.id ? '<button class="bi-button" type="submit">Create card</button>' : card.archived ? '<span class="bi-help">Restore this card to edit it.</span>' : ''}<span id="bi-save-status" class="bi-help" aria-live="polite"></span></div></form>
      ${card.id ? `<section class="bi-comments" aria-label="Comments"><h3>Comments <span id="bi-comment-count" class="bi-column-count">${panel.comments.length}</span></h3><div id="bi-comment-list">${commentsHTML()}</div>${!card.archived ? `<form id="bi-comment-form"><label for="bi-comment-text">Add a comment</label><textarea id="bi-comment-text" name="comment" rows="3" maxlength="10000" placeholder="Write a comment…"></textarea><div id="bi-image-previews" class="bi-image-previews"></div><div class="bi-comment-actions"><div class="bi-media-actions"><button type="button" class="bi-button bi-button--quiet" data-action="media-upload">Upload</button><button type="button" class="bi-button bi-button--quiet" data-action="media-select">Select</button></div><button type="submit" class="bi-button" disabled>Post comment</button></div><p class="bi-help">Choose images from your library, or drop and paste them here. Up to 10 images, ${Math.floor(cfg.maxUpload / 1048576)} MB each.</p><div id="bi-comment-error" class="bi-error" role="alert" tabindex="-1" hidden></div></form>` : ''}</section>` : '<p class="bi-help bi-new-help">Create the card first, then add comments.</p>'}
    </div></section>`;
    document.body.classList.add('bi-panel-open');
    document.getElementById(card.archived ? 'bi-panel-heading' : 'bi-title')?.focus();
    renderPreviews(); updateCommentState();
  }
  function commentsHTML() {
    if (!panel.comments.length) return '<p class="bi-no-comments">No comments yet. Start the conversation.</p>';
    return panel.comments.map(c => `<article class="bi-comment">${avatarHTML(c.author,c.avatarUrl)}<div class="bi-comment-body"><div class="bi-comment-by"><strong>${esc(c.author)}</strong><time datetime="${esc(c.date)}">${esc(new Date(c.date).toLocaleString(undefined, {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'}))}</time></div>${c.text ? `<p>${esc(c.text)}</p>` : ''}${c.images.length ? `<div class="bi-comment-images">${c.images.map(i => `<button type="button" class="bi-comment-image" data-action="image-open" data-image="${i.id}" aria-label="Open image: ${esc(i.name)}"><img src="${esc(i.thumb)}" alt="${esc(i.name)}" loading="lazy"></button>`).join('')}</div>` : ''}</div></article>`).join('');
  }
  async function openCard(id, column) {
    if (saving || dragging) return;
    if (panel && dirty()) { toast('Save or discard your changes before opening another card.', true); return; }
    lastFocus = document.activeElement;
    if (!id) {
      panel = {card: {id:0, title:'', description:'', column:todoColumn(), category:'', assignees:[], assigneeUsers:[], images:[], version:0, archived:false}, comments:[], files:[], taskImages:[]}; renderPanel(); return;
    }
    saving = true;
    try { const data = await api(`cards/${id}`); panel = {...data, files:[], taskImages:[...data.card.images]}; renderPanel(); }
    catch (e) { toast(e.message, true); }
    finally { saving = false; }
  }
  function setBusy(form,busy,label) {
    // Lock interaction without triggering WordPress's disabled field styling.
    form.inert = busy; form.setAttribute('aria-busy',String(busy));
    const submit = form.querySelector('button[type="submit"]');
    if(submit) {
      if(busy) { submit.dataset.label = submit.textContent; submit.dataset.wasDisabled = String(submit.disabled); submit.disabled = true; submit.textContent = label; }
      else { submit.disabled = submit.dataset.wasDisabled === 'true'; submit.textContent = submit.dataset.label || submit.textContent; }
    }
  }
  async function saveCard(form) {
    if (saving || !form) return false;
    const values = panelValues();
    if (!values.title.trim()) { errorIn('bi-card-error','Give your card a title before closing it.'); document.getElementById('bi-card-save-failure').hidden = false; form.elements.title.focus(); return false; }
    saving = true; setBusy(form,true,panel.card.id ? 'Saving…' : 'Creating…');
    document.getElementById('bi-card-error').hidden = true; document.getElementById('bi-card-save-failure').hidden = true; document.getElementById('bi-save-status').textContent = 'Saving…';
    const wasNew = !panel.card.id;
    let createdCard = null;
    try {
      const card = await api(wasNew ? 'cards' : `cards/${panel.card.id}`,{method:wasNew ? 'POST' : 'PATCH',body:{...values,version:panel.card.version}});
      panel.card = card;
      if(wasNew) {
        createdCard = card;
        if (!shown(card)) { filters.search = ''; filters.category = ''; filters.person = ''; filters.archived = false; }
        board.cards = [card, ...board.cards].sort((a,b) => a.order - b.order || a.id - b.id);
        renderBoard();
      }
      else { for(const key of ['title','description','category']) form.elements[key].value = String(card[key] || ''); form.querySelectorAll('input[name="assignees"]').forEach(input => { input.checked = card.assignees.includes(Number(input.value)); }); panel.taskImages = [...card.images]; renderTaskImages(); document.getElementById('bi-save-status').textContent = 'Saved'; }
      await loadBoard().catch(e => toast(`Card saved. ${e.message}`,true));
      return true;
    } catch(e) { document.getElementById('bi-save-status').textContent = 'Not saved'; document.getElementById('bi-card-save-failure').hidden = false; errorIn('bi-card-error',e.message); return false; }
    finally {
      saving = false; if(form.isConnected) setBusy(form,false); updateCommentState();
      if (createdCard) {
        finishClosePanel();
        const result = main.querySelector(`.bi-card-open[data-id="${createdCard.id}"]`);
        result?.focus(); result?.scrollIntoView({block:'nearest',inline:'nearest'});
      }
    }
  }
  let archiveFocus = null;
  function closeArchive(restoreFocus = true) {
    modalLayer.innerHTML = '';
    if(restoreFocus && archiveFocus?.isConnected) archiveFocus.focus(); archiveFocus = null;
  }
  function requestArchive() {
    if(saving || !panel?.card.id) return;
    if(panel.card.archived) { archiveCard(); return; }
    if(commentDirty()) { toast('Post or discard your comment before archiving this card.',true); return; }
    archiveFocus = document.activeElement;
    modalLayer.innerHTML = `<div class="bi-confirm-backdrop" data-action="archive-dismiss"></div><section class="bi-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="bi-archive-heading"><h2 id="bi-archive-heading">Archive this card?</h2><p><strong>${esc(panel.card.title)}</strong> will move to Archived cards. You can restore it at any time.</p><div id="bi-archive-error" class="bi-error" role="alert" hidden></div><div class="bi-actions"><button type="button" class="bi-button bi-button--quiet" data-action="archive-dismiss">Keep card</button><button type="button" class="bi-button bi-button--danger" data-action="archive-confirm">${icon('archive','Archive card')}</button></div></section>`;
    modalLayer.querySelector('button').focus();
  }
  async function archiveCard() {
    if(saving) return;
    if(commentDirty()) { toast('Post or discard your comment before archiving this card.',true); return; }
    if(cardDirty() && !await saveCard(document.getElementById('bi-card-form'))) { closeArchive(false); document.getElementById('bi-card-error')?.focus(); return; }
    saving = true;
    const archive = !panel.card.archived;
    modalLayer.querySelectorAll('button').forEach(b => { b.disabled = true; });
    try {
      await api(`cards/${panel.card.id}`,{method:'PATCH',body:{version:panel.card.version,archived:archive}});
      saving = false; closeArchive(false); finishClosePanel();
      await loadBoard(); toast(archive ? 'Card archived. You can restore it from Archived cards.' : 'Card restored.');
    } catch(e) {
      if(document.getElementById('bi-archive-error')) errorIn('bi-archive-error',e.message); else errorIn('bi-card-error',e.message);
    } finally { saving = false; modalLayer.querySelectorAll('button').forEach(b => { b.disabled = false; }); }
  }
  function queueFiles(files) {
    if (!panel || saving) return;
    for (const file of files) {
      if (panel.files.length >= 10) { toast('You can attach up to 10 images to a comment.', true); break; }
      if (!['image/jpeg','image/png','image/gif','image/webp','image/avif'].includes(file.type)) { toast('Choose a JPG, PNG, GIF, WebP, or AVIF image.', true); continue; }
      if (file.size > cfg.maxUpload) { toast(`${file.name} is too large. Use an image under ${Math.floor(cfg.maxUpload / 1048576)} MB.`, true); continue; }
      panel.files.push({file, preview:URL.createObjectURL(file), image:null});
    }
    renderPreviews(); updateCommentState();
  }
  function renderPreviews() {
    const target = document.getElementById('bi-image-previews'); if (!target || !panel) return;
    target.innerHTML = panel.files.map((f, index) => `<div class="bi-image-preview"><img src="${esc(f.preview)}" alt="${esc(f.file?.name || f.image?.name || 'Image')}"><button type="button" class="bi-image-remove" data-action="remove-image" data-index="${index}" aria-label="Remove ${esc(f.file?.name || f.image?.name || 'Image')}">${icon('no-alt')}</button></div>`).join('');
  }
  async function postComment(form) {
    if (saving) return;
    const text = document.getElementById('bi-comment-text').value;
    if (!text.trim() && !panel.files.length) { errorIn('bi-comment-error', 'Write a comment or add an image.'); return; }
    saving = true; setBusy(form, true, 'Posting…'); document.getElementById('bi-comment-error').hidden = true;
    try {
      for (let i = 0; i < panel.files.length; i++) {
        const entry = panel.files[i]; if (entry.image) continue;
        form.querySelector('button[type="submit"]').textContent = `Uploading ${i + 1} of ${panel.files.length}…`;
        const body = new FormData(); body.append('file', entry.file);
        const result = await api(`cards/${panel.card.id}/images`, {method:'POST', body}); entry.image = result.image;
        if (result.note) toast(result.note, true);
      }
      form.querySelector('button[type="submit"]').textContent = 'Posting…';
      const result = await api(`cards/${panel.card.id}/comments`, {method:'POST', body:{text, images:panel.files.map(f => f.image.id)}});
      // Do not overwrite another person's card edit or a user's unsaved form while posting.
      panel.comments = result.comments;
      disposePreviews(); panel.files = []; renderPreviews();
      document.getElementById('bi-comment-text').value = '';
      document.getElementById('bi-comment-list').innerHTML = commentsHTML();
      document.getElementById('bi-comment-count').textContent = panel.comments.length;
      document.querySelector('.bi-comment:last-child')?.scrollIntoView({block:'nearest'});
      await loadBoard();
    } catch (e) { errorIn('bi-comment-error', e.message); }
    finally { saving = false; setBusy(form, false); updateCommentState(); }
  }
  function closeImage() {
    modalLayer.innerHTML = ''; if(imageFocus?.isConnected) imageFocus.focus(); imageFocus = null;
  }
  function openImage(id) {
    const image = panel && [...panel.taskImages,...panel.comments.flatMap(c => c.images)].find(i => i.id === id); if(!image) return;
    imageFocus = document.activeElement;
    modalLayer.innerHTML = `<div class="bi-image-backdrop" data-action="image-close"></div><section class="bi-image-dialog" role="dialog" aria-modal="true" aria-label="Image preview"><button class="bi-icon-button" data-action="image-close" aria-label="Close image">${icon('no-alt')}</button><img src="${esc(image.url)}" alt="${esc(image.name)}"></section>`;
    modalLayer.querySelector('button').focus();
  }
  if(!cfg.settings) jQuery.ajaxPrefilter((options) => {
    if(!/admin-ajax\.php(?:\?|$)/.test(options.url) || typeof options.data !== 'string') return;
    const params = new URLSearchParams(options.data);
    if(!['query-attachments','get-attachment'].includes(params.get('action'))) return;
    params.set('boardino_media_nonce',cfg.mediaNonce); options.data = params.toString();
  });
  function openMedia(mode, target = 'comment') {
    if(saving || !panel || panel.card.archived || (target === 'comment' && !panel.card.id)) return;
    if(!window.wp?.media) { toast('The Media Library could not load. Reload this page and try again.',true); return; }
    const defaults = wp.Uploader?.defaults?.multipart_params;
    const previous = defaults ? {...defaults} : null;
    if(defaults) { defaults.boardino_media_nonce = cfg.mediaNonce; defaults.boardino_card = panel.card.id; }
    mediaOpen = true;
    const frame = wp.media({title:mode === 'upload' ? 'Upload images' : 'Select images',button:{text:target === 'task' ? 'Add to task' : 'Add to comment'},library:{type:'image'},multiple:true});
    mediaFrame = frame;
    frame.on('open',() => frame.content.mode(mode === 'upload' ? 'upload' : 'browse'));
    frame.on('select',() => {
      if(!panel) return;
      for(const item of frame.state().get('selection').toJSON()) {
        if(item.type !== 'image') continue;
        const selected = target === 'task' ? panel.taskImages : panel.files.map(f => f.image).filter(Boolean);
        if(selected.some(image => image.id === item.id)) continue;
        if((target === 'task' ? panel.taskImages.length : panel.files.length) >= 10) { toast('You can attach up to 10 images.',true); break; }
        const image = {id:item.id,url:item.url,thumb:item.sizes?.medium?.url || item.sizes?.thumbnail?.url || item.url,name:item.title || item.filename || 'Image'};
        if(target === 'task') panel.taskImages.push(image);
        else panel.files.push({file:null,preview:image.thumb,image});
      }
      if(target === 'task') renderTaskImages();
      else { renderPreviews(); updateCommentState(); }
    });
    frame.on('close',() => {
      mediaOpen = false; mediaFrame = null;
      setTimeout(() => frame.remove(),0);
      if(defaults && previous) { delete defaults.boardino_media_nonce; delete defaults.boardino_card; Object.assign(defaults,previous); }
      document.querySelector(`[data-action="${target === 'task' ? 'task-' : ''}media-${mode}"]`)?.focus();
    });
    frame.open();
  }
  const newId = prefix => `${prefix}_${Date.now().toString(36)}_${Math.random().toString(36).slice(2,8)}`;
  function settingsRow(item, kind) {
    return `<div class="bi-settings-row" data-id="${esc(item.id)}"><label class="screen-reader-text">${kind === 'columns' ? 'Column' : 'Category'} name</label><input type="text" name="${kind}_name" maxlength="60" value="${esc(item.name)}" placeholder="${kind === 'columns' ? 'Column name' : 'Category name'}" required>${kind === 'categories' ? colorControl(item.color,'Category color for '+item.name) : '<div class="bi-row-reorder"><button type="button" class="bi-icon-button" data-action="row-up" aria-label="Move column up">'+icon('arrow-up-alt2')+'</button><button type="button" class="bi-icon-button" data-action="row-down" aria-label="Move column down">'+icon('arrow-down-alt2')+'</button></div>'}<button type="button" class="bi-text-button bi-text-button--danger" data-action="remove-row">Remove</button></div>`;
  }
  function renderSettings() {
    const s = settingsData.settings;
    main.className = 'bi-main bi-main--settings';
    const folderValid = settingsData.folders.some(f => f.id === s.folder_id);
    main.innerHTML = `<div class="bi-heading"><div><h2>Board settings</h2><p>The essentials for your board and your team.</p></div>${cfg.boardAccess !== false ? `<a class="bi-button bi-button--quiet" href="${esc(cfg.boardUrl)}">Back to board</a>` : ''}</div><form id="bi-settings-form"><div id="bi-settings-error" class="bi-error" role="alert" tabindex="-1" hidden></div><section class="bi-settings-section"><div class="bi-settings-intro"><h3>Your board</h3><p>One shared place for the work.</p></div><div class="bi-settings-controls"><div class="bi-field"><label for="bi-board-name">Board name</label><input id="bi-board-name" name="board_name" type="text" value="${esc(s.name)}" maxlength="100" required></div></div></section>
      <section class="bi-settings-section"><div class="bi-settings-intro"><h3>User roles</h3><p>Choose who can use Boardino and be assigned to tasks.</p></div><div class="bi-settings-controls"><div class="bi-members" role="group" aria-label="Allowed user roles">${settingsData.roles.map(role => `<label class="bi-member"><input type="checkbox" name="role" value="${esc(role.id)}" ${(s.roles === null ? ['administrator'] : s.roles).includes(role.id) ? 'checked' : ''}><span>${esc(role.name)}</span></label>`).join('')}</div><p class="bi-help">Everyone in a selected role can use the board. Administrators can always manage these settings.</p>${settingsData.legacy_access ? '<p class="bi-help">Your existing individual access stays active until you save roles.</p>' : ''}</div></section>
      <section class="bi-settings-section"><div class="bi-settings-intro"><h3>Columns</h3><p>The steps a card moves through.</p></div><div class="bi-settings-controls"><div id="bi-columns" class="bi-settings-rows" data-kind="columns">${s.columns.map(c => settingsRow(c,'columns')).join('')}</div><button type="button" class="bi-button bi-button--quiet" data-action="add-row" data-kind="columns">${icon('plus-alt2','Add column')}</button><p class="bi-help">Use the arrows to reorder columns. Move all cards out before removing a column.</p></div></section>
      <section class="bi-settings-section"><div class="bi-settings-intro"><h3>Categories</h3><p>A simple label to tell cards apart.</p></div><div class="bi-settings-controls"><div id="bi-categories" class="bi-settings-rows" data-kind="categories">${s.categories.map(c => settingsRow(c,'categories')).join('')}</div><button type="button" class="bi-button bi-button--quiet" data-action="add-row" data-kind="categories">${icon('plus-alt2','Add category')}</button><p class="bi-help">Each card has one category. Removing a category keeps its cards.</p></div></section>
      <section class="bi-settings-section"><div class="bi-settings-intro"><h3>Images</h3><p>Images are saved in the WordPress Media Library.</p></div><div class="bi-settings-controls"><div class="bi-field"><label for="bi-media-folder">Media folder</label><select id="bi-media-folder" name="folder_id" ${!settingsData.folderino ? 'disabled' : ''}><option value="0">Media Library — no folder</option>${!settingsData.folderino && s.folder_id ? `<option value="${s.folder_id}" selected>Saved Folderino folder (currently unavailable)</option>` : ''}${settingsData.folderino && s.folder_id && !folderValid ? '<option value="" selected disabled>Selected folder no longer exists</option>' : ''}${options(settingsData.folders, s.folder_id)}</select></div><p class="bi-help">${settingsData.folderino ? 'Choose a Folderino folder for new task and comment images. Existing images stay in their current folders.' : 'Folderino is optional. Images still upload normally while it is inactive. Your saved folder choice will be used again when Folderino is active.'}</p>${settingsData.folderino ? '<p class="bi-help">Create and organize folders in <a href="'+esc(cfg.boardUrl.replace('admin.php?page=boardino','upload.php'))+'">Media Library</a>.</p>' : ''}</div></section><div class="bi-settings-save"><button class="bi-button" type="submit">Save settings</button><span id="bi-settings-status" class="bi-help" role="status"></span></div></form>`;
  }
  function readSettings() {
    const form = document.getElementById('bi-settings-form');
    const rows = kind => [...document.querySelectorAll(`#bi-${kind} .bi-settings-row`)].map(row => ({id:row.dataset.id, name:row.querySelector('input').value, ...(kind === 'categories' ? {color:row.querySelector('.bi-color-value').value} : {})}));
    return {name:form.elements.board_name.value, roles:[...form.querySelectorAll('input[name="role"]:checked')].map(i => i.value), folder_id:Number(form.elements.folder_id.value), columns:rows('columns'), categories:rows('categories'), revision:settingsData.revision};
  }
  let settingsBaseline = '';
  async function loadSettings() { settingsData = await api('settings'); cfg.boardAccess = settingsData.boardAccess; renderSettings(); settingsBaseline = JSON.stringify(readSettings()); }
  async function saveSettings(form) {
    if (saving) return;
    const values = readSettings(); saving = true; setBusy(form, true, 'Saving…'); document.getElementById('bi-settings-error').hidden = true;
    try { await api('settings', {method:'POST', body:values}); await loadSettings(); document.getElementById('bi-settings-status').textContent = 'Settings saved'; }
    catch (e) { errorIn('bi-settings-error', e.message); }
    finally { saving = false; if (form.isConnected) { setBusy(form,false); if (!settingsData.folderino) form.elements.folder_id.disabled = true; } }
  }
  document.getElementById('boardino').addEventListener('click', event => {
    if(colorPicker && !event.target.closest('.bi-color-control')) closeColorPicker();
    const el = event.target.closest('[data-action]'); if (!el) return;
    const action = el.dataset.action;
    if(action === 'color-picker') openColorPicker(el);
    if(action === 'color-done') { const hex = colorPicker?.popup.querySelector('.bi-color-hex'); if(hex && /^#[0-9a-f]{6}$/i.test(hex.value)) { const button = colorPicker.control.querySelector('button'); closeColorPicker(); button.focus(); } }
    if (action === 'column-open') openColumnComposer();
    if (action === 'column-close') closeColumnComposer();
    if (action === 'clear-filters') { filters.search = ''; filters.category = ''; filters.person = ''; renderBoard(); }
    if (action === 'open') openCard(Number(el.dataset.id));
    if (action === 'new') openCard(0, el.dataset.column);
    if (action === 'close') closePanel();
    if (action === 'keep') { document.getElementById('bi-discard').hidden = true; document.getElementById('bi-title')?.focus(); }
    if (action === 'discard') closePanel(true);
    if (action === 'discard-unsaved') finishClosePanel();
    if(action === 'archive-request') requestArchive();
    if(action === 'archive-dismiss' && !saving) closeArchive();
    if(action === 'archive-confirm') archiveCard();
    if(action === 'task-media-upload') openMedia('upload','task');
    if(action === 'task-media-select') openMedia('select','task');
    if(action === 'task-image-remove' && !saving && !panel.card.archived) {
      panel.taskImages = panel.taskImages.filter(image => image.id !== Number(el.dataset.image)); renderTaskImages();
      document.querySelector('[data-action="task-media-select"]')?.focus();
    }
    if(action === 'media-upload') openMedia('upload');
    if(action === 'media-select') openMedia('select');
    if(action === 'image-open') openImage(Number(el.dataset.image));
    if(action === 'image-close') closeImage();
    if(action === 'categories') { document.getElementById('bi-category-editor').hidden = !document.getElementById('bi-category-editor').hidden; }
    if(action === 'categories-close') document.getElementById('bi-category-editor').hidden = true;
    if(action === 'category-save') saveCategory(el);
    if(action === 'category' && !saving && !panel.card.archived) { const field = document.getElementById('bi-category'); field.value = field.value === el.dataset.category ? '' : el.dataset.category; document.getElementById('bi-category-options').innerHTML = categoryBubblesHTML(field.value); [...document.querySelectorAll('.bi-category-bubble')].find(b => b.dataset.category === el.dataset.category)?.focus(); }
    if (action === 'remove-image' && !saving) { const [entry] = panel.files.splice(Number(el.dataset.index),1); if(entry.preview?.startsWith('blob:')) URL.revokeObjectURL(entry.preview); renderPreviews(); updateCommentState(); }
    if (action === 'add-row' && !saving) {
      const kind = el.dataset.kind, target = document.getElementById(`bi-${kind}`);
      if (target.children.length >= 30) { toast(`Use up to 30 ${kind}.`, true); return; }
      target.insertAdjacentHTML('beforeend', settingsRow({id:newId(kind === 'columns' ? 'col' : 'cat'), name:''},kind)); target.lastElementChild.querySelector('input').focus();
    }
    if (action === 'remove-row' && !saving) {
      const row = el.closest('.bi-settings-row'), parent = row.parentElement;
      if (parent.dataset.kind === 'columns' && parent.children.length === 1) { toast('Keep at least one column.', true); return; }
      row.remove();
    }
    if (action === 'row-up' && !saving) { const row = el.closest('.bi-settings-row'); if(row.previousElementSibling) row.parentNode.insertBefore(row,row.previousElementSibling); el.focus(); }
    if (action === 'row-down' && !saving) { const row = el.closest('.bi-settings-row'); if(row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling,row); el.focus(); }
  });
  document.getElementById('boardino').addEventListener('submit', event => {
    if (!['bi-card-form','bi-comment-form','bi-settings-form','bi-column-form'].includes(event.target.id)) return;
    event.preventDefault();
    if (event.target.id === 'bi-column-form') addColumn(event.target);
    if (event.target.id === 'bi-card-form') saveCard(event.target);
    if (event.target.id === 'bi-comment-form') postComment(event.target);
    if (event.target.id === 'bi-settings-form') saveSettings(event.target);
  });
  main.addEventListener('input', event => {
    if (event.target.id === 'bi-column-name' && columnDraft) { columnDraft.name = event.target.value; event.target.form.querySelector('[type="submit"]').disabled = !columnDraft.name.trim(); return; }
    if (event.target.id !== 'bi-search') return;
    filters.search = event.target.value;
    const selection = event.target.selectionStart;
    renderBoard(); const input = document.getElementById('bi-search'); input.focus(); try { input.setSelectionRange(selection,selection); } catch (_) { /* Some browsers don't expose search-input selection. */ }
  });
  main.addEventListener('change', event => {
    if (event.target.id === 'bi-filter-category') filters.category = event.target.value;
    else if (event.target.id === 'bi-filter-person') filters.person = event.target.value;
    else if (event.target.id === 'bi-filter-view') filters.archived = event.target.value === 'archive';
    else return;
    renderBoard();
  });
  layer.addEventListener('input',event => { if(event.target.id === 'bi-comment-text') updateCommentState(); });
  layer.addEventListener('paste', event => {
    if (!event.target.closest('#bi-comment-form') || saving) return;
    const files = [...(event.clipboardData?.items || [])].filter(i => i.kind === 'file').map(i => i.getAsFile()).filter(Boolean);
    if (files.length) { event.preventDefault(); queueFiles(files); }
  });
  layer.addEventListener('dragover', event => { if(event.target.closest('#bi-comment-form')) { event.preventDefault(); event.dataTransfer.dropEffect = 'copy'; } });
  layer.addEventListener('drop', event => { if(event.target.closest('#bi-comment-form')) { event.preventDefault(); queueFiles(event.dataTransfer.files); } });
  document.addEventListener('keydown', event => {
    if(colorPicker && event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); const button = colorPicker.control.querySelector('button'); closeColorPicker(); button.focus(); return; }
    if (columnDraft && event.key === 'Escape' && event.target.closest('#bi-column-form')) { event.preventDefault(); closeColumnComposer(); return; }
    if (!panel || mediaOpen) return;
    if(modalLayer.children.length) {
      if(event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); if(modalLayer.querySelector('.bi-confirm-dialog')) { if(!saving) closeArchive(); } else closeImage(); }
      else if(event.key === 'Tab') {
        const buttons = [...modalLayer.querySelectorAll('button')].filter(b => !b.disabled), first = buttons[0], last = buttons[buttons.length-1];
        if(!buttons.length) { event.preventDefault(); return; }
        if(event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if(!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
      return;
    }
    if (event.key === 'Escape') { event.preventDefault(); closePanel(); }
    if (event.key === 'Tab') {
      const focusable = [...layer.querySelectorAll('button,input,select,textarea,a[href]')].filter(el => !el.disabled && el.offsetParent !== null && el.tabIndex !== -1);
      const first = focusable[0], last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }
  });
  window.addEventListener('beforeunload', event => { if (dirty() || (cfg.settings && settingsBaseline && settingsBaseline !== JSON.stringify(readSettings()))) { event.preventDefault(); event.returnValue = ''; } });
  (cfg.settings ? loadSettings() : loadBoard()).catch(e => { main.innerHTML = `<div class="bi-load-error"><h2>Couldn’t load ${cfg.settings ? 'settings' : 'your board'}</h2><p>${esc(e.message)}</p><a class="bi-button" href="${esc(cfg.settings ? cfg.settingsUrl : cfg.boardUrl)}">Try again</a></div>`; });
  if (!cfg.settings) setInterval(async () => { if (!panel && !columnDraft && !saving && !dragging && document.visibilityState === 'visible') { try { const fresh = await api('board'); if (board && (fresh.revision !== board.revision || JSON.stringify(fresh.users) !== JSON.stringify(board.users))) { board = fresh; renderBoard(); } } catch (_) { /* Keep a usable board during temporary connection loss. */ } } }, 30000);
})();
