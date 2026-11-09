document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl)
    })
    var requester = document.getElementById('requester_email');
    if (requester) {
        var timeout = null;
        var list = document.createElement('div');
        list.className = 'list-group position-absolute w-100';
        requester.parentNode.style.position = 'relative';
        requester.parentNode.appendChild(list);
        requester.addEventListener('input', function() {
            clearTimeout(timeout);
            var q = requester.value.trim();
            if (q.length < 3) { list.innerHTML=''; return; }
            timeout = setTimeout(function() {
                fetch('user_search.php?q=' + encodeURIComponent(q))
                  .then(r=>r.json())
                  .then(data=>{
                    list.innerHTML = '';
                    data.forEach(function(u){
                        var a = document.createElement('a');
                        a.href = '#';
                        a.className = 'list-group-item list-group-item-action';
                        a.textContent = u.email + ' — ' + u.name;
                        a.addEventListener('click', function(e){
                            e.preventDefault();
                            requester.value = u.email;
                            list.innerHTML = '';
                        });
                        list.appendChild(a);
                    })
                  });
            }, 300);
        });
        document.addEventListener('click', function(e){
            if (!requester.parentNode.contains(e.target)) list.innerHTML='';
        });
    }
    document.querySelectorAll('table').forEach(function(t){
        if (!t.closest('.table-responsive')) {
            var w = document.createElement('div');
            w.className = 'table-responsive';
            t.parentNode.insertBefore(w, t);
            w.appendChild(t);
        }
    });
    var wrapper = document.getElementById('wrapper');
    var toggler = document.getElementById('menu-toggle');
    var backdrop = document.createElement('div');
    backdrop.className = 'sidebar-backdrop';
    document.body.appendChild(backdrop);
    function isMobile(){ return window.innerWidth < 992; }
    function updateBackdrop(){
        if (!wrapper) return;
        var open = !wrapper.classList.contains('toggled') && isMobile();
        backdrop.classList.toggle('active', open);
    }
    function initSidebarState(){
        if (wrapper) {
            if (isMobile()) wrapper.classList.add('toggled');
            else wrapper.classList.remove('toggled');
            updateBackdrop();
        }
    }
    if (toggler && wrapper) {
        toggler.addEventListener('click', function(){
            wrapper.classList.toggle('toggled');
            updateBackdrop();
        });
    }
    backdrop.addEventListener('click', function(){
        if (wrapper) {
            wrapper.classList.add('toggled');
            updateBackdrop();
        }
    });
    window.addEventListener('resize', initSidebarState);
    initSidebarState();

    // Lógica para indicadores de carga global
    const loadingOverlay = document.getElementById('loading-overlay');
    
    function showLoading() {
        if (loadingOverlay) {
            loadingOverlay.classList.remove('d-none');
        }
    }

    document.addEventListener('submit', function(e) {
        const form = e.target;
        if (form.tagName === 'FORM' && form.getAttribute('method')?.toUpperCase() === 'POST') {
            // Evitar múltiples envíos si ya está cargando
            if (form.dataset.isSubmitting === 'true') {
                e.preventDefault();
                return;
            }
            
            // Si el formulario es válido, mostrar carga
            if (form.checkValidity()) {
                form.dataset.isSubmitting = 'true';
                showLoading();
            }
        }
    });
});
