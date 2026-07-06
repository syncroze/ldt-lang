(function () {
	var toggleBtn = document.querySelector('.sidebar-toggle');
	var sidebar = document.querySelector('.sidebar');
	var overlay = document.querySelector('.sidebar-overlay');

	function closeSidebar() {
		sidebar.classList.remove('open');
		overlay.classList.remove('visible');
		if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
	}

	function openSidebar() {
		sidebar.classList.add('open');
		overlay.classList.add('visible');
		if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
	}

	if (toggleBtn) {
		toggleBtn.addEventListener('click', function () {
			sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
		});
	}
	if (overlay) {
		overlay.addEventListener('click', closeSidebar);
	}
	document.querySelectorAll('.sidebar-toc a').forEach(function (a) {
		a.addEventListener('click', closeSidebar);
	});

	var links = Array.prototype.slice.call(document.querySelectorAll('.sidebar-toc a[href^="#"]'));
	var targets = links
		.map(function (a) {
			var el = document.getElementById(a.getAttribute('href').slice(1));
			return el ? { link: a, el: el } : null;
		})
		.filter(Boolean);

	if ('IntersectionObserver' in window && targets.length) {
		var current = null;
		var observer = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
					if (!entry.isIntersecting) return;
					var match = targets.filter(function (t) {
						return t.el === entry.target;
					})[0];
					if (match) {
						if (current) current.link.classList.remove('active');
						match.link.classList.add('active');
						current = match;
						match.link.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
					}
				});
			},
			{ rootMargin: '0px 0px -70% 0px', threshold: 0 }
		);
		targets.forEach(function (t) {
			observer.observe(t.el);
		});
	}
})();
