
  const STORAGE_KEY = 'restroai_panel_state_v2';

  function seedTables(){
    const seats = [4,4,2,6,4,2,4,4,6,2,4,4,2,4,4];
    return seats.map((s,i) => ({id:i+1, name:'T-' + String(i+1).padStart(2,'0'), seats:s}));
  }

  const defaultState = {
    tables: seedTables(),
    orders: [
      {id:1, tableId:4, items:'Margherita, Iced Tea', amount:460, status:'new', ts:Date.now()-2*60000},
      {id:2, tableId:11, items:'Butter Chicken Bowl', amount:320, status:'kitchen', ts:Date.now()-6*60000},
      {id:3, tableId:7, items:'Truffle Pasta ×2', amount:880, status:'ready', ts:Date.now()-9*60000},
      {id:4, tableId:2, items:'Paneer Tikka, Naan ×3', amount:540, status:'served', ts:Date.now()-14*60000},
      {id:5, tableId:14, items:'Cold Coffee, Brownie', amount:250, status:'new', ts:Date.now()-1*60000},
      {id:6, tableId:9, items:'Veg Biryani, Raita', amount:390, status:'kitchen', ts:Date.now()-4*60000}
    ],
    menu: [
      {id:1, emoji:'🍝', cat:'Mains', name:'Truffle Pasta', price:440, desc:'Hand-rolled pasta, black truffle cream.', active:true},
      {id:2, emoji:'🍔', cat:'Mains', name:'Signature Burger', price:320, desc:'Smash patty, aged cheddar, house sauce.', active:true},
      {id:3, emoji:'🍛', cat:'Mains', name:'Butter Chicken Bowl', price:360, desc:'Slow-simmered tomato butter gravy.', active:true},
      {id:4, emoji:'🥗', cat:'Starters', name:'Quinoa Salad', price:260, desc:'Quinoa, citrus, roasted vegetables.', active:false},
      {id:5, emoji:'🍰', cat:'Desserts', name:'Molten Brownie', price:210, desc:'Warm brownie, vanilla bean ice cream.', active:true},
      {id:6, emoji:'🍹', cat:'Beverages', name:'Cold Coffee', price:150, desc:'Espresso, cold milk, whipped cream.', active:true},
      {id:7, emoji:'🍕', cat:'Mains', name:'Margherita Pizza', price:340, desc:'San Marzano tomato, fresh basil.', active:true},
      {id:8, emoji:'🍜', cat:'Starters', name:'Veg Biryani', price:280, desc:'Fragrant basmati, saffron, vegetables.', active:false}
    ]
  };

  let state = loadState();
  let orderTab = 'active';
  let editingMenuId = null;
  let orderDraft = {}; // dishId -> qty, for the order modal in progress

  function loadState(){
    try{
      const raw = localStorage.getItem(STORAGE_KEY);
      if(raw){
        const parsed = JSON.parse(raw);
        if(parsed.tables && parsed.orders && parsed.menu) return parsed;
      }
    }catch(e){ console.warn('Could not load saved state', e); }
    return JSON.parse(JSON.stringify(defaultState));
  }
  function persist(){
    try{ localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); }
    catch(e){ console.warn('Could not save state', e); }
  }

  const STATUS_FLOW = ['new','kitchen','ready','served','completed'];
  const STATUS_LABEL = {new:'New', kitchen:'Kitchen', ready:'Ready', served:'Served', completed:'Completed'};
  const STATUS_NEXT_LABEL = {new:'Send to kitchen ▸', kitchen:'Mark ready ▸', ready:'Mark served ▸', served:'Complete ▸'};

  function timeAgo(ts){
    const mins = Math.max(0, Math.round((Date.now()-ts)/60000));
    if(mins < 1) return 'just now';
    if(mins === 1) return '1m ago';
    if(mins < 60) return mins+'m ago';
    return Math.round(mins/60)+'h ago';
  }
  function tableName(id){
    const t = state.tables.find(t=>t.id===id);
    return t ? t.name : 'Unassigned';
  }
  function occupiedTableIds(){
    return new Set(state.orders.filter(o=>o.status!=='completed').map(o=>o.tableId));
  }

  function setOrderTab(tab){
    orderTab = tab;
    document.getElementById('tabActive').classList.toggle('active', tab==='active');
    document.getElementById('tabCompleted').classList.toggle('active', tab==='completed');
    renderOrders();
  }

  function advanceOrder(id){
    const order = state.orders.find(o => o.id === id);
    if(!order) return;
    const idx = STATUS_FLOW.indexOf(order.status);
    if(idx < STATUS_FLOW.length - 1){
      order.status = STATUS_FLOW[idx+1];
      persist();
      renderOrders();
      renderTables();
    }
  }

  function renderOrders(){
    const list = document.getElementById('orderList');
    if(list){
      const filtered = state.orders.filter(o => orderTab === 'completed' ? o.status === 'completed' : o.status !== 'completed');
      const sorted = filtered.slice().sort((a,b)=> b.ts - a.ts);

      if(sorted.length === 0){
        list.innerHTML = `<div class="empty-note">${orderTab==='completed' ? 'No completed orders yet.' : 'No active orders — tap "New Order" to add one.'}</div>`;
      } else {
        list.innerHTML = sorted.map(o => `
          <div class="order-row">
            <div class="table-id">${tableName(o.tableId)}</div>
            <div class="items">${o.items}<span>${orderTab==='completed' ? 'Completed' : STATUS_LABEL[o.status]} · ${timeAgo(o.ts)}</span></div>
            <div><span class="status-pill status-${o.status}">${STATUS_LABEL[o.status]}</span></div>
            <div class="amount">₹${o.amount}</div>
            <div class="action-cell">
              ${o.status !== 'completed' ? `<button class="advance-btn" onclick="advanceOrder(${o.id})">${STATUS_NEXT_LABEL[o.status]}</button>` : ''}
            </div>
          </div>
        `).join('');
      }
    }
    const navBadge = document.getElementById('navOrderCount');
    if(navBadge) navBadge.textContent = state.orders.filter(o=>o.status!=='completed').length;
    renderStats();
  }

  function renderStats(){
    if(!document.getElementById('statRevenue')) return;
    const active = state.orders;
    const totalRevenue = active.reduce((sum,o)=> sum + Number(o.amount||0), 0);
    const count = active.length;
    const avg = count ? Math.round(totalRevenue/count) : 0;
    const occ = occupiedTableIds().size;
    document.getElementById('statRevenue').textContent = '₹' + totalRevenue.toLocaleString('en-IN');
    document.getElementById('statOrders').textContent = count;
    document.getElementById('statAvg').textContent = '₹' + avg;
    document.getElementById('statOccupied').textContent = occ + ' / ' + state.tables.length;
    document.getElementById('statOccupiedNote').textContent = occ === 0 ? 'All clear' : (occ === state.tables.length ? 'Fully booked' : 'Filling up');
  }

  /* ---------------- ORDERS ---------------- */
  function openOrderModal(){
    orderDraft = {};
    const select = document.getElementById('orderTableSelect');
    const occupied = occupiedTableIds();
    if(state.tables.length === 0){
      select.innerHTML = `<option value="">No tables yet — add one first</option>`;
    } else {
      select.innerHTML = state.tables.map(t => {
        const busy = occupied.has(t.id);
        return `<option value="${t.id}" ${busy ? 'disabled' : ''}>${t.name} · ${t.seats} seats${busy ? ' (Occupied)' : ''}</option>`;
      }).join('');
    }
    renderItemPicker();
    updateOrderTotal();
    document.getElementById('orderModalOverlay').classList.add('open');
  }
  function closeOrderModal(){ document.getElementById('orderModalOverlay').classList.remove('open'); }

  function renderItemPicker(){
    const picker = document.getElementById('itemPicker');
    const activeDishes = state.menu.filter(d=>d.active);
    if(activeDishes.length === 0){
      picker.innerHTML = `<div class="empty-picker-note">No active menu items — add or enable one in Menu Management.</div>`;
      return;
    }
    picker.innerHTML = activeDishes.map(d => `
      <div class="item-picker-row">
        <div>
          <div class="ipr-name">${d.emoji || '🍽'} ${d.name}</div>
          <span class="ipr-price">₹${d.price}</span>
        </div>
        <div class="qty-stepper">
          <button onclick="changeQty(${d.id}, -1)">−</button>
          <span class="qty-val" id="qty-${d.id}">${orderDraft[d.id] || 0}</span>
          <button onclick="changeQty(${d.id}, 1)">+</button>
        </div>
      </div>
    `).join('');
  }

  function changeQty(dishId, delta){
    const current = orderDraft[dishId] || 0;
    const next = Math.max(0, current + delta);
    orderDraft[dishId] = next;
    document.getElementById('qty-'+dishId).textContent = next;
    updateOrderTotal();
  }

  function updateOrderTotal(){
    let total = 0;
    Object.keys(orderDraft).forEach(id => {
      const dish = state.menu.find(d=>d.id===Number(id));
      if(dish) total += dish.price * orderDraft[id];
    });
    document.getElementById('orderTotalDisplay').textContent = '₹' + total.toLocaleString('en-IN');
    return total;
  }

  function saveOrder(){
    const tableId = Number(document.getElementById('orderTableSelect').value);
    if(!tableId){ alert('Please select a table.'); return; }
    const chosen = Object.keys(orderDraft).filter(id => orderDraft[id] > 0);
    if(chosen.length === 0){ alert('Please select at least one item from the menu.'); return; }
    const itemsStr = chosen.map(id => {
      const dish = state.menu.find(d=>d.id===Number(id));
      const qty = orderDraft[id];
      return qty > 1 ? `${dish.name} ×${qty}` : dish.name;
    }).join(', ');
    const amount = updateOrderTotal();
    const nextId = Math.max(0, ...state.orders.map(o=>o.id)) + 1;
    state.orders.push({id: nextId, tableId, items: itemsStr, amount, status:'new', ts:Date.now()});
    persist();
    closeOrderModal();
    setOrderTab('active');
    renderTables();
  }

  /* ---------------- MENU ---------------- */
  function renderMenu(){
    const grid = document.getElementById('menuGrid');
    if(!grid) return;
    const cards = state.menu.map(d => `
      <div class="dish-card ${d.active ? '' : 'inactive'}">
        <div class="dish-top">
          <div class="dish-emoji">${d.emoji || '🍽'}</div>
          <button class="toggle ${d.active ? 'on' : ''}" onclick="toggleDish(${d.id})"></button>
        </div>
        <div><span class="cat">${d.cat}</span><h4>${d.name}</h4></div>
        ${d.desc ? `<div class="desc">${d.desc}</div>` : ''}
        <div class="dish-foot">
          <span class="dish-price">₹${d.price}</span>
          <div class="dish-actions">
            <button class="a" onclick="openMenuModal(${d.id})" title="Edit">✎</button>
            <button class="a" onclick="deleteDish(${d.id})" title="Delete">✕</button>
          </div>
        </div>
      </div>
    `).join('');
    grid.innerHTML = cards + `<button class="dish-card add-dish-card" onclick="openMenuModal()"><span class="plus">+</span>Add menu item</button>`;
    const activeCount = state.menu.filter(d=>d.active).length;
    document.getElementById('menuCount').textContent = `${state.menu.length} dishes · ${activeCount} active`;
  }

  function toggleDish(id){
    const dish = state.menu.find(d=>d.id===id);
    if(!dish) return;
    dish.active = !dish.active;
    persist();
    renderMenu();
  }
  function deleteDish(id){
    const dish = state.menu.find(d=>d.id===id);
    if(!dish) return;
    if(!confirm(`Remove "${dish.name}" from the menu?`)) return;
    state.menu = state.menu.filter(d=>d.id!==id);
    persist();
    renderMenu();
  }
  function openMenuModal(id){
    editingMenuId = id || null;
    const modalTitle = document.getElementById('menuModalTitle');
    const saveBtn = document.getElementById('menuSaveBtn');
    if(id){
      const dish = state.menu.find(d=>d.id===id);
      modalTitle.textContent = 'Edit Menu Item';
      saveBtn.textContent = 'Save Changes';
      document.getElementById('dishEmoji').value = dish.emoji || '';
      document.getElementById('dishName').value = dish.name;
      document.getElementById('dishCat').value = dish.cat;
      document.getElementById('dishPrice').value = dish.price;
      document.getElementById('dishDesc').value = dish.desc || '';
      document.getElementById('dishActive').checked = dish.active;
    } else {
      modalTitle.textContent = 'Add Menu Item';
      saveBtn.textContent = 'Add Item';
      document.getElementById('dishEmoji').value = '🍽';
      document.getElementById('dishName').value = '';
      document.getElementById('dishCat').value = 'Mains';
      document.getElementById('dishPrice').value = '';
      document.getElementById('dishDesc').value = '';
      document.getElementById('dishActive').checked = true;
    }
    document.getElementById('menuModalOverlay').classList.add('open');
  }
  function closeMenuModal(){ document.getElementById('menuModalOverlay').classList.remove('open'); }
  function saveMenuItem(){
    const name = document.getElementById('dishName').value.trim();
    const price = Number(document.getElementById('dishPrice').value) || 0;
    if(!name || !price){ alert('Please add at least a name and price.'); return; }
    const payload = {
      emoji: document.getElementById('dishEmoji').value.trim() || '🍽',
      name,
      cat: document.getElementById('dishCat').value,
      price,
      desc: document.getElementById('dishDesc').value.trim(),
      active: document.getElementById('dishActive').checked
    };
    if(editingMenuId){
      Object.assign(state.menu.find(d=>d.id===editingMenuId), payload);
    } else {
      const nextId = Math.max(0, ...state.menu.map(d=>d.id)) + 1;
      state.menu.push({id: nextId, ...payload});
    }
    persist();
    closeMenuModal();
    renderMenu();
  }

  /* ---------------- TABLES ---------------- */
  let editingTableId = null;

  function renderTables(){
    const grid = document.getElementById('tableGrid');
    if(grid){
      const occupied = occupiedTableIds();
      const cards = state.tables.map(t => {
        const busy = occupied.has(t.id);
        return `
          <div class="table-card ${busy ? 'occupied' : ''}">
            <div class="table-top">
              <div class="table-num">${t.name.replace('T-','')}</div>
            </div>
            <div><h4>${t.name}</h4><span class="seats">${t.seats} seats</span></div>
            <span class="table-status ${busy ? 'busy' : 'free'}">${busy ? 'Occupied' : 'Free'}</span>
            <div class="table-foot">
              <div class="dish-actions">
                <button class="a" onclick="openTableModal(${t.id})" title="Edit">✎</button>
                <button class="a" onclick="deleteTable(${t.id})" title="Delete">✕</button>
              </div>
            </div>
          </div>
        `;
      }).join('');
      grid.innerHTML = cards + `<button class="table-card add-table-card" onclick="openTableModal()"><span class="plus">+</span>Add table</button>`;
    }
    const tableCountEl = document.getElementById('tableCount');
    if(tableCountEl) tableCountEl.textContent = `${state.tables.length} tables`;
    const navTableCountEl = document.getElementById('navTableCount');
    if(navTableCountEl) navTableCountEl.textContent = state.tables.length;
    renderStats();
  }

  function openTableModal(id){
    editingTableId = id || null;
    const title = document.getElementById('tableModalTitle');
    const saveBtn = document.getElementById('tableSaveBtn');
    if(id){
      const t = state.tables.find(t=>t.id===id);
      title.textContent = 'Edit Table';
      saveBtn.textContent = 'Save Changes';
      document.getElementById('tableName').value = t.name;
      document.getElementById('tableSeats').value = t.seats;
    } else {
      title.textContent = 'Add Table';
      saveBtn.textContent = 'Add Table';
      const nextNum = state.tables.length + 1;
      document.getElementById('tableName').value = 'T-' + String(nextNum).padStart(2,'0');
      document.getElementById('tableSeats').value = 4;
    }
    document.getElementById('tableModalOverlay').classList.add('open');
  }
  function closeTableModal(){ document.getElementById('tableModalOverlay').classList.remove('open'); }
  function saveTable(){
    const name = document.getElementById('tableName').value.trim();
    const seats = Number(document.getElementById('tableSeats').value) || 2;
    if(!name){ alert('Please name the table.'); return; }
    if(editingTableId){
      Object.assign(state.tables.find(t=>t.id===editingTableId), {name, seats});
    } else {
      const nextId = Math.max(0, ...state.tables.map(t=>t.id)) + 1;
      state.tables.push({id: nextId, name, seats});
    }
    persist();
    closeTableModal();
    renderTables();
  }
  function deleteTable(id){
    if(occupiedTableIds().has(id)){ alert('This table has an active order — complete or clear it before deleting.'); return; }
    if(!confirm('Remove this table?')) return;
    state.tables = state.tables.filter(t=>t.id!==id);
    persist();
    renderTables();
  }

  const orderModalOverlay = document.getElementById('orderModalOverlay');
  if(orderModalOverlay) orderModalOverlay.addEventListener('click', e => { if(e.target.id==='orderModalOverlay') closeOrderModal(); });
  const menuModalOverlay = document.getElementById('menuModalOverlay');
  if(menuModalOverlay) menuModalOverlay.addEventListener('click', e => { if(e.target.id==='menuModalOverlay') closeMenuModal(); });
  const tableModalOverlay = document.getElementById('tableModalOverlay');
  if(tableModalOverlay) tableModalOverlay.addEventListener('click', e => { if(e.target.id==='tableModalOverlay') closeTableModal(); });

  // Highlight whichever sidebar link matches the current page, so the
  // active state is always correct without hand-editing every file.
  (function markActiveNavLink(){
    const here = location.pathname.split('/').pop() || 'overview.html';
    document.querySelectorAll('.nav-item').forEach(link => {
      const href = (link.getAttribute('href') || '').split('/').pop();
      link.classList.toggle('active', href === here);
    });
  })();

  renderOrders();
  renderMenu();
  renderTables();
