// Variables globales pour la pagination et l'état
var currentBooksPage = 1;
var currentThesesPage = 1;
var booksPerPage = 6;
var thesesPerPage = 6;
var isBook = true;
var isThesis = false;

// --- FONCTION CENTRALE DE FILTRAGE (CORRIGÉE POUR LA PAGINATION) ---
function applyFilters(resetPage = false) {
    if (resetPage) {
        currentBooksPage = 1;
        currentThesesPage = 1;
    }

    var searchQuery = document.getElementById('searchBar').value.toLowerCase();
    var selectedDepartment = document.getElementById('departmentFilter').value;

    const filterItem = (item, type) => {
        const nameField = type === 'books' ? 'name' : 'Nom';
        const categoryField = type === 'books' ? 'cathegorie' : 'département';
        const name = item.fields[nameField] ? item.fields[nameField].stringValue.toLowerCase() : '';
        const category = item.fields[categoryField] ? item.fields[categoryField].stringValue.toLowerCase() : '';
        const searchMatch = name.includes(searchQuery) || category.includes(searchQuery);
        const itemDepartment = item.fields[categoryField] ? item.fields[categoryField].stringValue : '';
        const departmentMatch = !selectedDepartment || itemDepartment === selectedDepartment;
        return searchMatch && departmentMatch;
    };

    var filteredBooksData = booksData.filter(item => filterItem(item, 'books'));
    var filteredThesesData = thesesData.filter(item => filterItem(item, 'theses'));

    if (isBook) {
        displayItems(filteredBooksData, 'books');
        renderPagination(filteredBooksData, 'books');
    } else if (isThesis) {
        displayItems(filteredThesesData, 'theses');
        renderPagination(filteredThesesData, 'theses');
    }
}


// --- FONCTION POUR LES RECOMMANDATIONS (CORRIGÉE) ---
function displayRecommendations() {
    const recommendationsList = document.getElementById('recommendationsList');
    if (!recommendationsList) return;

    const allItems = [...booksData, ...thesesData];
    if (allItems.length === 0) {
        recommendationsList.innerHTML = '<p>Aucun document à recommander pour le moment.</p>';
        return;
    }

    for (let i = allItems.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [allItems[i], allItems[j]] = [allItems[j], allItems[i]];
    }

    const recommendationsCount = 4;
    const selectedItems = allItems.slice(0, Math.min(recommendationsCount, allItems.length));

    recommendationsList.innerHTML = '';
    selectedItems.forEach(item => {
        const isBookItem = !!item.fields.cathegorie;
        const name = item.fields.name ? item.fields.name.stringValue : (item.fields.Nom ? item.fields.Nom.stringValue : 'Titre non disponible');
        const category = item.fields.cathegorie ? item.fields.cathegorie.stringValue : (item.fields.département ? item.fields.département.stringValue : 'Catégorie non disponible');
        const docId = item.name.split('/').pop();
        const type = isBookItem ? 'books' : 'theses';
        const typeLabel = isBookItem ? 'Livre' : 'Mémoire';
        const detailUrl = `view.php?id=${docId}&type=${type}`;

        const itemHTML = `
            <a href="${detailUrl}" class="recommendation-item-link">
                <div class="recommendation-item">
                    <span class="recommendation-badge">${typeLabel}</span>
                    <div class="recommendation-title" title="${name}">${name}</div>
                    <p class="recommendation-category">${category}</p>
                    <p class="recommendation-reason">Suggestion pour vous</p>
                </div>
            </a>
        `;
        recommendationsList.innerHTML += itemHTML;
    });
}


// --- FONCTION D'AFFICHAGE DES ITEMS  ---
function displayItems(items, type) {
    var listElement = document.getElementById(type + 'List');
    listElement.innerHTML = ''; 
    listElement.style.display = 'flex';

    if (items.length === 0) { 
        listElement.innerHTML = '<div class="no-results">Aucun document trouvé.</div>'; 
        return; 
    }

    var startIdx = (type === 'books' ? currentBooksPage - 1 : currentThesesPage - 1) * (type === 'books' ? booksPerPage : thesesPerPage);
    var endIdx = startIdx + (type === 'books' ? booksPerPage : thesesPerPage);
    var currentPageItems = items.slice(startIdx, endIdx);

    currentPageItems.forEach(item => {
        var name = item.fields.name ? item.fields.name.stringValue : (item.fields.Nom ? item.fields.Nom.stringValue : 'Nom non disponible');
        var category = item.fields.cathegorie ? item.fields.cathegorie.stringValue : (item.fields.département ? item.fields.département.stringValue : 'Catégorie non disponible');
        var imageUrl = item.fields.image ? item.fields.image.stringValue : 'images/default-image.png';
        var docId = item.name.split('/').pop();
        const detailUrl = `view.php?id=${docId}&type=${type}`;
        
        // Récupérer le nombre d'exemplaires (ajouté dans explore.php)
        var exemplaire = item.exemplaire || 0;
        
        // Préparer l'affichage des exemplaires
        var exemplaireHtml = '';
        if (exemplaire > 0) {
            exemplaireHtml = '<span class="text-success"><strong>' + exemplaire + ' exemplaire(s)</strong></span>';
        } else {
            exemplaireHtml = '<span class="text-danger"><strong>Hors Stock</strong></span>';
        }

        // --- CORRECTION : Logique pour le bouton Réserver/Réservé ---
        let reserveButtonHtml = '';
        // On vérifie si l'ID du document est dans la liste des réservations de l'utilisateur
        if (userReservationIds.includes(docId)) {
            reserveButtonHtml = `<button class="btn btn-secondary book-btn" disabled>Réservé</button>`;
        } else if (exemplaire > 0) {
            reserveButtonHtml = `<button class="btn btn-primary book-btn book-btn-reserve" data-id="${docId}" data-type="${type}" onclick="reserveItem(this)">Réserver</button>`;
        } else {
            reserveButtonHtml = `<button class="btn btn-secondary book-btn" disabled>Indisponible</button>`;
        }

        var itemHTML = `
            <div class="book-item">
                <div class="book-image"><a href="${detailUrl}"><img src="${imageUrl}" alt="${name}"></a></div>
                <div class="book-info">
                    <h3 class="book-title" title="${name}"><a href="${detailUrl}">${name}</a></h3>
                    <p class="book-category" title="${category}">
                        ${category}
                        <span style="margin-left: 10px;">${exemplaireHtml}</span>
                    </p>
                    <div class="book-actions">
                        <a href="${detailUrl}" class="btn btn-secondary book-btn">Détails</a>
                        ${reserveButtonHtml}
                    </div>
                </div>
            </div>
        `;
        listElement.innerHTML += itemHTML;
    });
}

// --- FONCTION DE RÉSERVATION (RESTAURÉE) ---
function reserveItem(element) {
    var itemId = element.getAttribute('data-id');
    var itemType = element.getAttribute('data-type');
    
    var allItems = [...booksData, ...thesesData];
    var item = allItems.find(i => i.name.split('/').pop() === itemId);
    
    if (item) {
        var itemName = item.fields.name ? item.fields.name.stringValue : (item.fields.Nom ? item.fields.Nom.stringValue : 'Nom non disponible');
        
        if (!confirm(`Vous êtes sur le point de réserver "${itemName}".\nConfirmer ?`)) {
            return;
        }

        const reservationData = {
            itemId: itemId,
            itemType: itemType,
            userDocId: userDocId
        };

        element.disabled = true;
        element.textContent = 'En cours...';

        fetch('api_reserve.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(reservationData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Réservation réussie !');
                element.textContent = 'Réservé';
                element.style.backgroundColor = '#6c757d';
            } else {
                alert('Échec de la réservation : ' + data.message);
                element.disabled = false;
                element.textContent = 'Réserver';
            }
        })
        .catch(error => {
            console.error('Erreur lors de la réservation:', error);
            alert('Une erreur réseau est survenue. Veuillez réessayer.');
            element.disabled = false;
            element.textContent = 'Réserver';
        });
    }
}


// --- PAGINATION (CORRIGÉE) ---
function renderPagination(items, type) {
    var paginationElement = document.querySelector('.pagination');
    if (paginationElement) paginationElement.remove();

    var totalPages = Math.ceil(items.length / (type === 'books' ? booksPerPage : thesesPerPage));
    if (totalPages <= 1) return;

    paginationElement = document.createElement('div');
    paginationElement.classList.add('pagination');
    
    const currentPage = type === 'books' ? currentBooksPage : currentThesesPage;

    if (currentPage > 1) {
        var prevButton = document.createElement('a');
        prevButton.href = "#";
        prevButton.classList.add('page-link');
        prevButton.textContent = 'Préc';
        prevButton.onclick = function(e) {
            e.preventDefault();
            if (type === 'books') currentBooksPage--;
            else currentThesesPage--;
            applyFilters(false);
        };
        paginationElement.appendChild(prevButton);
    }

    for (var i = 1; i <= totalPages; i++) {
        var pageButton = document.createElement('a');
        pageButton.href = "#";
        pageButton.classList.add('page-link');
        pageButton.textContent = i;
        if (currentPage === i) pageButton.classList.add('active');
        
        pageButton.onclick = (function(pageNumber) {
            return function(e) {
                e.preventDefault();
                if (type === 'books') currentBooksPage = pageNumber;
                else currentThesesPage = pageNumber;
                applyFilters(false);
            };
        })(i);
        paginationElement.appendChild(pageButton);
    }

    if (currentPage < totalPages) {
        var nextButton = document.createElement('a');
        nextButton.href = "#";
        nextButton.classList.add('page-link');
        nextButton.textContent = 'Suiv';
        nextButton.onclick = function(e) {
            e.preventDefault();
            if (type === 'books') currentBooksPage++;
            else currentThesesPage++;
            applyFilters(false);
        };
        paginationElement.appendChild(nextButton);
    }

    var contentArea = document.getElementById('contentArea');
    contentArea.appendChild(paginationElement);
}

// --- ÉCOUTEURS D'ÉVÉNEMENTS (CORRIGÉS POUR LES BOUTONS) ---
document.addEventListener('DOMContentLoaded', (event) => {
    document.getElementById('searchBar').addEventListener('input', () => applyFilters(true));
    document.getElementById('departmentFilter').addEventListener('change', () => applyFilters(true));

    document.getElementById('switchBooks').addEventListener('click', function() {
        if (isBook) return;
        
        // Mettre à jour l'état
        isBook = true;
        isThesis = false;
        
        // Afficher/masquer les listes
        document.getElementById('booksList').style.display = 'flex';
        document.getElementById('thesesList').style.display = 'none';
        
        // CORRECTION : Mettre à jour les classes des boutons
        this.classList.remove('btn-secondary');
        this.classList.add('btn-primary', 'active');
        
        document.getElementById('switchTheses').classList.remove('btn-primary', 'active');
        document.getElementById('switchTheses').classList.add('btn-secondary');
        
        applyFilters(true);
    });

    document.getElementById('switchTheses').addEventListener('click', function() {
        if (isThesis) return;
        
        // Mettre à jour l'état
        isBook = false;
        isThesis = true;
        
        // Afficher/masquer les listes
        document.getElementById('thesesList').style.display = 'flex';
        document.getElementById('booksList').style.display = 'none';
        
        // CORRECTION : Mettre à jour les classes des boutons
        this.classList.remove('btn-secondary');
        this.classList.add('btn-primary', 'active');
        
        document.getElementById('switchBooks').classList.remove('btn-primary', 'active');
        document.getElementById('switchBooks').classList.add('btn-secondary');
        
        applyFilters(true);
    });

    // Initialisation (le bouton Livres est déjà actif par défaut)
    document.getElementById('switchBooks').classList.add('active');
    applyFilters(true);
    displayRecommendations();
});