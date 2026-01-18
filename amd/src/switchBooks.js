// Variables globales pour la pagination et l'état
var currentBooksPage = 1;
var currentThesesPage = 1;
var booksPerPage = 6;
var thesesPerPage = 6;
var isBook = true;
var isThesis = false;

// --- FONCTION DE TRI ---
function sortItems(items, type, sortBy) {
    const sortedItems = [...items];
    
    switch(sortBy) {
        case 'title_asc':
            return sortedItems.sort((a, b) => {
                const nameA = a.fields.name ? a.fields.name.stringValue.toLowerCase() : 
                             (a.fields.Nom ? a.fields.Nom.stringValue.toLowerCase() : '');
                const nameB = b.fields.name ? b.fields.name.stringValue.toLowerCase() : 
                             (b.fields.Nom ? b.fields.Nom.stringValue.toLowerCase() : '');
                return nameA.localeCompare(nameB);
            });
            
        case 'title_desc':
            return sortedItems.sort((a, b) => {
                const nameA = a.fields.name ? a.fields.name.stringValue.toLowerCase() : 
                             (a.fields.Nom ? a.fields.Nom.stringValue.toLowerCase() : '');
                const nameB = b.fields.name ? b.fields.name.stringValue.toLowerCase() : 
                             (b.fields.Nom ? b.fields.Nom.stringValue.toLowerCase() : '');
                return nameB.localeCompare(nameA);
            });
            
        case 'department_asc':
            return sortedItems.sort((a, b) => {
                const deptA = a.fields.cathegorie ? a.fields.cathegorie.stringValue.toLowerCase() : 
                             (a.fields.département ? a.fields.département.stringValue.toLowerCase() : '');
                const deptB = b.fields.cathegorie ? b.fields.cathegorie.stringValue.toLowerCase() : 
                             (b.fields.département ? b.fields.département.stringValue.toLowerCase() : '');
                return deptA.localeCompare(deptB);
            });
            
        case 'department_desc':
            return sortedItems.sort((a, b) => {
                const deptA = a.fields.cathegorie ? a.fields.cathegorie.stringValue.toLowerCase() : 
                             (a.fields.département ? a.fields.département.stringValue.toLowerCase() : '');
                const deptB = b.fields.cathegorie ? b.fields.cathegorie.stringValue.toLowerCase() : 
                             (b.fields.département ? b.fields.département.stringValue.toLowerCase() : '');
                return deptB.localeCompare(deptA);
            });
            
        case 'availability_desc': // Plus d'exemplaires d'abord
            return sortedItems.sort((a, b) => {
                const availA = a.exemplaire || 0;
                const availB = b.exemplaire || 0;
                return availB - availA;
            });
            
        case 'availability_asc': // Moins d'exemplaires d'abord
            return sortedItems.sort((a, b) => {
                const availA = a.exemplaire || 0;
                const availB = b.exemplaire || 0;
                return availA - availB;
            });
            
        default:
            return sortedItems;
    }
}

// --- FONCTION CENTRALE DE FILTRAGE ---
function applyFilters(resetPage = false) {
    if (resetPage) {
        currentBooksPage = 1;
        currentThesesPage = 1;
    }

    var searchQuery = document.getElementById('searchBar').value.toLowerCase();
    var selectedDepartment = document.getElementById('departmentFilter').value;
    var sortBy = document.getElementById('sortFilter').value;

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

    // APPLIQUER LE TRI 
    filteredBooksData = sortItems(filteredBooksData, 'books', sortBy);
    filteredThesesData = sortItems(filteredThesesData, 'theses', sortBy);

    if (isBook) {
        displayItems(filteredBooksData, 'books');
        renderPagination(filteredBooksData, 'books');
    } else if (isThesis) {
        displayItems(filteredThesesData, 'theses');
        renderPagination(filteredThesesData, 'theses');
    }
}

// --- FONCTION D'AFFICHAGE DES ITEMS ---
function displayItems(items, type) {
    var listElement = document.getElementById(type + 'List');
    if (!listElement) return;
    
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
        
        // Récupérer le nombre d'exemplaires
        var exemplaire = item.exemplaire || 0;
        
        // Vérifier si déjà réservé
        const isAlreadyReserved = userReservationIds.includes(docId);
        
        // Préparer l'affichage des exemplaires
        var exemplaireHtml = '';
        if (exemplaire > 0) {
            exemplaireHtml = '<span class="text-success" title="Nombre d\'exemplaire(s) disponible(s) dans la bibliotheque"><strong>' + exemplaire + ' exemplaire(s)</strong></span>';
        } else {
            exemplaireHtml = '<span class="text-danger" title="Ce document est actuellement hors stock"><strong>Hors Stock</strong></span>';
        }

        // --- LOGIQUE POUR LE BOUTON RÉSERVER/ANNULER ---
        let reserveButtonHtml = '';
        
        // 1. Vérifier si le livre est déjà réservé par l'utilisateur
        if (isAlreadyReserved) {
            // BOUTON D'ANNULATION
            reserveButtonHtml = `<button class="btn btn-warning book-btn book-btn-cancel" 
                data-id="${docId}" 
                data-type="${type}" 
                onclick="cancelReservation(this)"
                style="cursor: pointer;"
                title="Cliquez pour annuler cette réservation">
                <i class="fa fa-times-circle"></i> Annuler
            </button>`;
        } else if (exemplaire <= 0) {
            // Pas disponible du tout
            reserveButtonHtml = `<button class="btn btn-secondary book-btn" disabled style="cursor: not-allowed; opacity: 0.6;">
                Indisponible
            </button>`;
        } else {
            // Disponible et non réservé → bouton actif
            reserveButtonHtml = `<button class="btn btn-primary book-btn book-btn-reserve" 
                data-id="${docId}" 
                data-type="${type}" 
                onclick="reserveItem(this)"
                style="cursor: pointer;"
                title="Cliquez pour réserver ce document">
                Réserver
            </button>`;
        }

        var itemHTML = `
            <div class="book-item">
                <div class="book-image"><a href="${detailUrl}"><img src="${imageUrl}" alt="${name}"></a></div>
                <div class="book-info">
                    <h3 class="book-title" title="${name}"><a href="${detailUrl}">${name}</a></h3>
                    <p class="book-category" title="${category}">
                        ${category}
                        <span style="margin-left: 10px;">${exemplaireHtml}</span>
                        ${isAlreadyReserved ? '<span class="badge badge-warning ml-2">Réservé par vous</span>' : ''}
                    </p>
                    <div class="book-actions">
                        <a href="${detailUrl}" class="btn btn-outline-primary book-btn" style="flex: 1;">Détails</a>
                        ${reserveButtonHtml}
                    </div>
                </div>
            </div>
        `;
        listElement.innerHTML += itemHTML;
    });
}

// --- FONCTION DE RÉSERVATION ---
async function reserveItem(element) {
    var itemId = element.getAttribute('data-id');
    var itemType = element.getAttribute('data-type');
    
    // VÉRIFICATION SUPPLEMENTAIRE : Empêcher la réservation si déjà réservé
    if (userReservationIds.includes(itemId)) {
        alert('❌ Vous avez déjà réservé ce document !');
        return;
    }
    
    var allItems = [...booksData, ...thesesData];
    var item = allItems.find(i => i.name.split('/').pop() === itemId);
    
    if (!item) {
        alert('❌ Document introuvable');
        return;
    }
    
    var itemName = item.fields.name ? item.fields.name.stringValue : 
                  (item.fields.Nom ? item.fields.Nom.stringValue : 'Nom non disponible');
    
    // Confirmation simple
    if (!confirm(`Voulez-vous réserver :\n"${itemName}" ?`)) {
        return;
    }
    
    const reservationData = {
        itemId: itemId,
        itemType: itemType,
        userDocId: userDocId
    };

    // Sauvegarder l'état original du bouton
    const originalText = element.textContent;
    const originalClass = element.className;
    
    // Mettre à jour l'état du bouton
    element.disabled = true;
    element.textContent = 'En cours...';
    element.style.cursor = 'wait';

    try {
        const response = await fetch('api_reserve.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(reservationData)
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('✅ Réservation réussie !');
            
            // 1. Mettre à jour la liste locale des réservations
            if (!userReservationIds.includes(itemId)) {
                userReservationIds.push(itemId);
            }
            
            // 2. Mettre à jour l'affichage du bouton
            element.textContent = 'Réservé';
            element.classList.remove('btn-primary', 'book-btn-reserve');
            element.classList.add('btn-secondary');
            element.disabled = true;
            element.style.cursor = 'not-allowed';
            element.style.opacity = '0.6';
            
            // 3. Mettre à jour le badge "Réservé par vous"
            const parentElement = element.closest('.book-info');
            if (parentElement) {
                const categoryElement = parentElement.querySelector('.book-category');
                if (categoryElement && !categoryElement.querySelector('.badge-warning')) {
                    const badge = document.createElement('span');
                    badge.className = 'badge badge-warning ml-2';
                    badge.textContent = 'Réservé par vous';
                    categoryElement.appendChild(badge);
                }
            }
            
            // 4. Mettre à jour le compteur d'exemplaires
            const itemIndex = allItems.findIndex(i => i.name.split('/').pop() === itemId);
            if (itemIndex !== -1 && allItems[itemIndex].exemplaire > 0) {
                allItems[itemIndex].exemplaire--;
                
                // Mettre à jour l'affichage du compteur
                const exemplaireSpan = document.querySelector(`[data-id="${itemId}"]`)
                    ?.closest('.book-info')
                    ?.querySelector('.text-success, .text-danger');
                
                if (exemplaireSpan) {
                    const newCount = allItems[itemIndex].exemplaire;
                    if (newCount > 0) {
                        exemplaireSpan.innerHTML = `<strong>${newCount} exemplaire(s)</strong>`;
                    } else {
                        exemplaireSpan.innerHTML = '<strong>Hors Stock</strong>';
                        exemplaireSpan.classList.remove('text-success');
                        exemplaireSpan.classList.add('text-danger');
                    }
                }
            }
            
        } else {
            // Échec
            alert('❌ Échec: ' + (data.message || 'Erreur inconnue'));
            
            // Restaurer le bouton
            element.disabled = false;
            element.textContent = originalText;
            element.className = originalClass;
            element.style.cursor = '';
        }
        
    } catch (error) {
        console.error('Erreur lors de la réservation:', error);
        alert('❌ Erreur réseau');
        
        // Restaurer le bouton
        element.disabled = false;
        element.textContent = originalText;
        element.className = originalClass;
        element.style.cursor = '';
    }
}

// === NOUVELLE FONCTION : ANNULATION DE RÉSERVATION ===
async function cancelReservation(element) {
    const itemId = element.getAttribute('data-id');
    const itemType = element.getAttribute('data-type');
    
    // Trouver le nom du document pour la confirmation
    const allItems = [...booksData, ...thesesData];
    const item = allItems.find(i => i.name.split('/').pop() === itemId);
    
    if (!item) {
        alert('❌ Document introuvable');
        return;
    }
    
    const itemName = item.fields.name ? item.fields.name.stringValue : 
                    (item.fields.Nom ? item.fields.Nom.stringValue : 'Nom non disponible');
    
    // Demander confirmation
    if (!confirm(`Voulez-vous annuler la réservation de :\n"${itemName}" ?`)) {
        return;
    }
    
    // Sauvegarder l'état original du bouton
    const originalText = element.textContent;
    const originalClass = element.className;
    
    // Mettre à jour l'état du bouton
    element.disabled = true;
    element.textContent = 'Annulation...';
    element.style.cursor = 'wait';
    
    try {
        // Appeler l'API d'annulation
        const response = await fetch('api_cancel.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                itemId: itemId,
                itemType: itemType,
                userDocId: userDocId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('✅ Réservation annulée !');
            
            // 1. Retirer de la liste des réservations locales
            const index = userReservationIds.indexOf(itemId);
            if (index > -1) {
                userReservationIds.splice(index, 1);
            }
            
            // 2. Mettre à jour le bouton
            element.textContent = 'Annulé';
            element.classList.remove('btn-warning', 'book-btn-cancel');
            element.classList.add('btn-secondary');
            element.disabled = true;
            element.style.opacity = '0.6';
            
            // 3. Retirer le badge "Réservé par vous"
            const parentElement = element.closest('.book-info');
            if (parentElement) {
                const badge = parentElement.querySelector('.badge-warning');
                if (badge) {
                    badge.remove();
                }
            }
            
            // 4. Mettre à jour le compteur d'exemplaires (incrémenter)
            const itemIndex = allItems.findIndex(i => i.name.split('/').pop() === itemId);
            if (itemIndex !== -1) {
                // Incrémenter le compteur
                allItems[itemIndex].exemplaire = (allItems[itemIndex].exemplaire || 0) + 1;
                
                // Mettre à jour l'affichage du compteur
                const exemplaireSpan = document.querySelector(`[data-id="${itemId}"]`)
                    ?.closest('.book-info')
                    ?.querySelector('.text-success, .text-danger');
                
                if (exemplaireSpan) {
                    const newCount = allItems[itemIndex].exemplaire;
                    if (newCount > 0) {
                        exemplaireSpan.innerHTML = `<strong>${newCount} exemplaire(s)</strong>`;
                        exemplaireSpan.classList.remove('text-danger');
                        exemplaireSpan.classList.add('text-success');
                    }
                }
                
                // 5. Si le document redevient disponible, changer le bouton pour les autres
                // On recharge simplement les filtres pour une mise à jour complète
                setTimeout(() => {
                    applyFilters(false);
                }, 1000);
            }
            
        } else {
            // Erreur
            alert('❌ Échec: ' + (data.message || 'Erreur inconnue'));
            
            // Restaurer le bouton
            element.disabled = false;
            element.textContent = originalText;
            element.className = originalClass;
            element.style.cursor = '';
        }
        
    } catch (error) {
        console.error('Erreur lors de l\'annulation:', error);
        alert('❌ Erreur réseau');
        
        // Restaurer le bouton
        element.disabled = false;
        element.textContent = originalText;
        element.className = originalClass;
        element.style.cursor = '';
    }
}

// --- PAGINATION ---
function renderPagination(items, type) {
    var paginationElement = document.querySelector('.pagination');
    if (paginationElement) paginationElement.remove();

    var totalPages = Math.ceil(items.length / (type === 'books' ? booksPerPage : thesesPerPage));
    if (totalPages <= 1) return;

    paginationElement = document.createElement('div');
    paginationElement.classList.add('pagination');
    paginationElement.style.display = 'flex';
    paginationElement.style.justifyContent = 'center';
    paginationElement.style.margin = '20px 0';
    paginationElement.style.gap = '5px';
    
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
    if (contentArea) {
        contentArea.appendChild(paginationElement);
    }
}

// --- ÉCOUTEURS D'ÉVÉNEMENTS ---
document.addEventListener('DOMContentLoaded', (event) => {
    const searchBar = document.getElementById('searchBar');
    const departmentFilter = document.getElementById('departmentFilter');
    const sortFilter = document.getElementById('sortFilter');
    
    if (searchBar) {
        searchBar.addEventListener('input', () => applyFilters(true));
    }
    
    if (departmentFilter) {
        departmentFilter.addEventListener('change', () => applyFilters(true));
    }
    
    if (sortFilter) {
        sortFilter.addEventListener('change', () => applyFilters(true));
    }

    const switchBooksBtn = document.getElementById('switchBooks');
    const switchThesesBtn = document.getElementById('switchTheses');
    
    if (switchBooksBtn) {
        switchBooksBtn.addEventListener('click', function() {
            if (isBook) return;
            
            // Mettre à jour l'état
            isBook = true;
            isThesis = false;
            
            // Afficher/masquer les listes
            const booksList = document.getElementById('booksList');
            const thesesList = document.getElementById('thesesList');
            if (booksList) booksList.style.display = 'flex';
            if (thesesList) thesesList.style.display = 'none';
            
            // Mettre à jour les classes des boutons
            this.classList.remove('btn-secondary');
            this.classList.add('btn-primary', 'active');
            
            if (switchThesesBtn) {
                switchThesesBtn.classList.remove('btn-primary', 'active');
                switchThesesBtn.classList.add('btn-secondary');
            }
            
            applyFilters(true);
        });
    }

    if (switchThesesBtn) {
        switchThesesBtn.addEventListener('click', function() {
            if (isThesis) return;
            
            // Mettre à jour l'état
            isBook = false;
            isThesis = true;
            
            // Afficher/masquer les listes
            const thesesList = document.getElementById('thesesList');
            const booksList = document.getElementById('booksList');
            if (thesesList) thesesList.style.display = 'flex';
            if (booksList) booksList.style.display = 'none';
            
            // Mettre à jour les classes des boutons
            this.classList.remove('btn-secondary');
            this.classList.add('btn-primary', 'active');
            
            if (switchBooksBtn) {
                switchBooksBtn.classList.remove('btn-primary', 'active');
                switchBooksBtn.classList.add('btn-secondary');
            }
            
            applyFilters(true);
        });
    }

    // Initialisation
    if (switchBooksBtn) {
        switchBooksBtn.classList.add('active');
    }
    
    applyFilters(true);
});

// --- NOTIFICATION HELPER SIMPLIFIÉ ---
class SimpleNotificationHelper {
    static success(message, title = 'Succès') {
        alert(title + ': ' + message);
    }
    
    static error(message, title = 'Erreur') {
        alert(title + ': ' + message);
    }
    
    static confirm(message, title = 'Confirmation') {
        return confirm(title + ':\n\n' + message);
    }
}

// Exporter les fonctions globales si besoin
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        applyFilters,
        displayItems,
        reserveItem,
        cancelReservation,
        renderPagination
    };
}