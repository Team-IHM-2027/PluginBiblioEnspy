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
            
        case 'availability_desc':
            return sortedItems.sort((a, b) => {
                const availA = a.exemplaire || 0;
                const availB = b.exemplaire || 0;
                return availB - availA;
            });
            
        case 'availability_asc':
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

// ========================================================================
// NOUVELLE FONCTION : Charger recommandations depuis l'API
// ========================================================================
async function loadUserRecommendations() {
    const recommendationsList = document.getElementById('recommendationsList');
    
    if (!recommendationsList) {
        console.warn('Element recommendationsList non trouvé');
        return;
    }
    
    // Afficher un loader
    recommendationsList.innerHTML = `
        <div style="display: flex; justify-content: center; align-items: center; width: 100%; min-height: 200px;">
            <div style="text-align: center;">
                <i class="fa fa-spinner fa-spin fa-3x text-primary"></i>
                <p class="mt-3 text-muted">Chargement des recommandations...</p>
            </div>
        </div>
    `;
    
    try {
        const payload = {
            booksData: window.booksData || [],
            thesesData: window.thesesData || []
        };
        
        const response = await fetch('ajax_recommendations_user.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message || 'Erreur inconnue');
        }
        
        // Afficher les recommandations de l'API
        displayRecommendationsFromAPI(data.recommendations, data.source);
        
    } catch (error) {
        console.error('Erreur chargement recommandations:', error);
        // FALLBACK : Utiliser la fonction existante
        displayRecommendations();
    }
}

// ========================================================================
// NOUVELLE FONCTION : Afficher recommandations depuis l'API
// ========================================================================
function displayRecommendationsFromAPI(recommendations, source) {
    const recommendationsList = document.getElementById('recommendationsList');
    
    if (!recommendationsList) return;
    
    // Nettoyer
    recommendationsList.innerHTML = '';
    
    // Style inline pour forcer l'horizontal
    recommendationsList.style.display = 'flex';
    recommendationsList.style.flexWrap = 'nowrap';
    recommendationsList.style.overflowX = 'auto';
    recommendationsList.style.overflowY = 'hidden';
    recommendationsList.style.gap = '25px';
    recommendationsList.style.padding = '15px 15px';
    recommendationsList.style.boxSizing = 'border-box';
    recommendationsList.style.alignItems = 'flex-start';
    recommendationsList.style.scrollPaddingLeft = '5px';
    recommendationsList.style.scrollPaddingRight = '5px';
    
    if (!recommendations || recommendations.length === 0) {
        recommendationsList.innerHTML = `
            <div class="no-recommendations" style="width: 100%; text-align: center; padding: 40px;">
                <i class="fa fa-info-circle fa-3x text-muted mb-3"></i>
                <p class="text-muted">Aucune recommandation disponible pour le moment.</p>
            </div>
        `;
        return;
    }
    
    const recommendationPhrases = [
        "Recommandé pour vous",
        "Basé sur votre profil",
        "Utilisateurs similaires ont consulté",
        "Populaire dans votre département",
        "En lien avec vos intérêts"
    ];
    
    recommendations.forEach((item, index) => {
        const isBookItem = !!item.fields.cathegorie;
        const name = item.fields.name ? item.fields.name.stringValue : 
                    (item.fields.Nom ? item.fields.Nom.stringValue : 
                    (item.fields.theme ? item.fields.theme.stringValue : 'Titre non disponible'));
        
        const category = item.fields.cathegorie ? item.fields.cathegorie.stringValue : 
                        (item.fields.département ? item.fields.département.stringValue : 'Catégorie non disponible');
        
        const docId = item.name.split('/').pop();
        const type = isBookItem ? 'books' : 'theses';
        const typeLabel = isBookItem ? 'Livre' : 'Mémoire';
        const detailUrl = `view.php?id=${docId}&type=${type}`;
        
        const exemplaire = item.exemplaire || 0;
        const disponibiliteHtml = exemplaire > 0 ?
            `<span class="badge badge-success" title="${exemplaire} exemplaire(s) disponible(s)">${exemplaire}</span>` :
            `<span class="badge badge-danger" title="Hors stock">0</span>`;
        
        const truncatedName = name.length > 35 ? name.substring(0, 35) + '...' : name;
        const truncatedCategory = category.length > 25 ? category.substring(0, 25) + '...' : category;
        
        // Score de recommandation (si disponible depuis l'API)
        const recScore = item.recommendation_score || item.similarity_score || 0;
        const scoreDisplay = recScore > 0 ? `Score: ${Math.round(recScore)}%` : '';
        
        // Phrase selon la source
        let reasonPhrase = source === 'api' && recScore > 75 ? 
            "Fortement recommandé pour vous" : 
            recommendationPhrases[index % recommendationPhrases.length];
        
        const imageUrl = item.fields.image ? item.fields.image.stringValue : 'images/default-image.png';
        
        const itemHTML = `
            <div class="recommendation-item" style="flex: 0 0 auto; width: 220px; box-sizing: border-box; min-height: 340px;">
                <a href="${detailUrl}" class="recommendation-link" style="display: block; height: 100%; text-decoration: none; color: inherit;">
                    <div class="recommendation-image" style="width: 100%; height: 220px; overflow: hidden; background: #f5f5f5;">
                        <img src="${imageUrl}" 
                             alt="${name}"
                             onerror="this.src='images/default-image.png'"
                             style="width: 100%; height: 100%; object-fit: cover; display: block;">
                    </div>
                    <div class="recommendation-content" style="padding: 12px; display: flex; flex-direction: column; box-sizing: border-box; min-height: 120px;">
                        <span class="recommendation-type" style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 4px 10px; border-radius: 12px; font-size: 0.75em; font-weight: 600; margin-bottom: 8px;">${typeLabel}</span>
                        <h4 class="recommendation-title" style="font-size: 1em; font-weight: 600; color: #333; margin: 0 0 6px 0; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; min-height: 2.4em;" title="${name}">${truncatedName}</h4>
                        <p class="recommendation-category" style="font-size: 0.85em; color: #666; margin: 0 0 4px 0; font-style: italic; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${category}">${truncatedCategory}</p>
                        <div style="margin-bottom: 6px;">${disponibiliteHtml}</div>
                        ${scoreDisplay ? `<p style="font-size: 0.75em; color: #28a745; margin: 4px 0; font-weight: 600;">${scoreDisplay}</p>` : ''}
                        <p class="recommendation-reason" style="font-size: 0.8em; color: #17a2b8; margin: 0; padding-top: 6px; border-top: 1px dashed #e9ecef; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; flex-grow: 1;" title="${reasonPhrase}">${reasonPhrase}</p>
                    </div>
                </a>
            </div>
        `;
        
        recommendationsList.innerHTML += itemHTML;
    });
    
    // Badge pour indiquer la source (si fallback)
    if (source === 'fallback') {
        const parentContainer = recommendationsList.parentElement;
        if (parentContainer && !parentContainer.querySelector('.fallback-badge')) {
            const fallbackBadge = document.createElement('div');
            fallbackBadge.className = 'fallback-badge';
            fallbackBadge.style.cssText = 'position: absolute; top: 10px; right: 10px; background: #ffc107; color: #000; padding: 5px 10px; border-radius: 5px; font-size: 0.75em; font-weight: 600; z-index: 10;';
            fallbackBadge.innerHTML = '<i class="fa fa-random"></i> Sélection';
            parentContainer.style.position = 'relative';
            parentContainer.appendChild(fallbackBadge);
        }
    }
}

// ========================================================================
// FONCTION ORIGINALE : displayRecommendations (FALLBACK)
// ========================================================================
function displayRecommendations() {
    const recommendationsList = document.getElementById('recommendationsList');
    if (!recommendationsList) return;

    const allItems = [...booksData, ...thesesData];
    if (allItems.length === 0) {
        recommendationsList.innerHTML = '<div class="no-recommendations">Aucun document à recommander pour le moment.</div>';
        return;
    }

    // Mélanger les éléments
    for (let i = allItems.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [allItems[i], allItems[j]] = [allItems[j], allItems[i]];
    }

    const recommendationPhrases = [
        "Populaire dans votre département",
        "Nouvel ajout à la bibliothèque",
        "Basé sur votre niveau d'études",
        "Document fréquemment consulté",
        "Sujet en rapport avec vos intérêts",
        "Document de votre promotion"
    ];

    const recommendationsCount = Math.min(10, allItems.length);
    const selectedItems = allItems.slice(0, recommendationsCount);

    // Nettoyer et appliquer le style inline
    recommendationsList.innerHTML = '';
    recommendationsList.style.display = 'flex';
    recommendationsList.style.flexWrap = 'nowrap';
    recommendationsList.style.overflowX = 'auto';
    recommendationsList.style.overflowY = 'hidden';
    recommendationsList.style.gap = '25px';
    recommendationsList.style.padding = '15px 15px';
    recommendationsList.style.boxSizing = 'border-box';
    recommendationsList.style.alignItems = 'flex-start';
    recommendationsList.style.scrollPaddingLeft = '5px';
    recommendationsList.style.scrollPaddingRight = '5px';

    selectedItems.forEach((item) => {
        const isBookItem = !!item.fields.cathegorie;
        const name = item.fields.name ? item.fields.name.stringValue : (item.fields.Nom ? item.fields.Nom.stringValue : 'Titre non disponible');
        const category = item.fields.cathegorie ? item.fields.cathegorie.stringValue : (item.fields.département ? item.fields.département.stringValue : 'Catégorie non disponible');
        const docId = item.name.split('/').pop();
        const type = isBookItem ? 'books' : 'theses';
        const typeLabel = isBookItem ? 'Livre' : 'Mémoire';
        const detailUrl = `view.php?id=${docId}&type=${type}`;

        const randomPhrase = recommendationPhrases[Math.floor(Math.random() * recommendationPhrases.length)];

        const exemplaire = item.exemplaire || 0;
        const disponibiliteHtml = exemplaire > 0 ?
            `<span class="badge badge-success" title="${exemplaire} exemplaire(s) disponible(s)">${exemplaire}</span>` :
            `<span class="badge badge-danger" title="Ce document est actuellement hors stock">0</span>`;

        const truncatedName = name.length > 35 ? name.substring(0, 35) + '...' : name;
        const truncatedCategory = category.length > 25 ? category.substring(0, 25) + '...' : category;

        const itemHTML = `
            <div class="recommendation-item" style="flex: 0 0 auto; width: 220px; box-sizing: border-box; min-height: 340px;">
                <a href="${detailUrl}" class="recommendation-link" style="display: block; height: 100%; text-decoration: none; color: inherit;">
                    <div class="recommendation-image" style="width: 100%; height: 220px; overflow: hidden; background: #f5f5f5;">
                        <img src="${item.fields.image ? item.fields.image.stringValue : 'images/default-image.png'}"
                             alt="${name}"
                             onerror="this.src='images/default-image.png'"
                             style="width: 100%; height: 100%; object-fit: cover; display:block;">
                    </div>
                    <div class="recommendation-content" style="padding: 12px; display: flex; flex-direction: column; flex: 1 1 auto; box-sizing: border-box; min-height: 100px;">
                        <span class="recommendation-type" style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 4px 10px; border-radius: 12px; font-size: 0.75em; font-weight: 600; margin-bottom: 8px;">${typeLabel}</span>
                        <h4 class="recommendation-title" style="font-size: 1em; font-weight: 600; color: #333; margin: 0 0 6px 0; line-height: 1.2; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; min-height: 2.6em;" title="${name}">${truncatedName}</h4>
                        <p class="recommendation-category" style="font-size: 0.85em; color: #666; margin: 0 0 4px 0; font-style: italic; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${category}">${truncatedCategory}</p>
                        <div style="margin-bottom: 6px;">${disponibiliteHtml}</div>
                        <p class="recommendation-reason" style="font-size: 0.8em; color: #17a2b8; margin: 0; padding-top: 6px; border-top: 1px dashed #e9ecef; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; flex-grow: 1; min-height: 2.6em;" title="${randomPhrase}">${randomPhrase}</p>
                    </div>
                </a>
            </div>
        `;
        recommendationsList.innerHTML += itemHTML;
    });
}

// --- FONCTION POUR LE DÉFILEMENT HORIZONTAL ---
function initHorizontalScroll() {
    const recommendationsList = document.getElementById('recommendationsList');
    const scrollLeftBtn = document.querySelector('.scroll-left');
    const scrollRightBtn = document.querySelector('.scroll-right');
    if (!recommendationsList) return;

    const scrollAmount = 300;

    if (scrollLeftBtn) {
        scrollLeftBtn.addEventListener('click', () => {
            recommendationsList.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
        });
    }
    if (scrollRightBtn) {
        scrollRightBtn.addEventListener('click', () => {
            recommendationsList.scrollBy({ left: scrollAmount, behavior: 'smooth' });
        });
    }

    function updateScrollButtons() {
        if (!recommendationsList) return;
        if (scrollLeftBtn) {
            scrollLeftBtn.style.opacity = recommendationsList.scrollLeft > 0 ? '1' : '0.5';
        }
        if (scrollRightBtn) {
            const maxScrollLeft = recommendationsList.scrollWidth - recommendationsList.clientWidth;
            scrollRightBtn.style.opacity = recommendationsList.scrollLeft < maxScrollLeft ? '1' : '0.5';
        }
    }

    recommendationsList.addEventListener('scroll', updateScrollButtons);
    recommendationsList.tabIndex = 0;
    recommendationsList.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowRight') recommendationsList.scrollBy({ left: 150, behavior: 'smooth' });
        if (e.key === 'ArrowLeft') recommendationsList.scrollBy({ left: -150, behavior: 'smooth' });
    });

    updateScrollButtons();
}

// --- FONCTION D'AFFICHAGE DES ITEMS ---
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
        
        var exemplaire = item.exemplaire || 0;
        
        var exemplaireHtml = '';
        if (exemplaire > 0) {
            exemplaireHtml = '<span class="text-success" title="Nombre d\'exemplaire(s) disponible(s) dans la bibliotheque"><strong>' + exemplaire + ' exemplaire(s)</strong></span>';
        } else {
            exemplaireHtml = '<span class="text-danger" title="Ce document est actuellement hors stock"><strong>Hors Stock</strong></span>';
        }

        let reserveButtonHtml = '';
        const isAlreadyReserved = userReservationIds.includes(docId);
        const isAvailable = exemplaire > 0;
        
        if (isAlreadyReserved) {
            reserveButtonHtml = `<button class="btn btn-secondary book-btn" disabled style="cursor: not-allowed; opacity: 0.6;">
                <i class="fa fa-check-circle"></i> Déjà réservé
            </button>`;
        } else if (!isAvailable) {
            reserveButtonHtml = `<button class="btn btn-secondary book-btn" disabled style="cursor: not-allowed; opacity: 0.6;">
                Indisponible
            </button>`;
        } else {
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
    
    if (userReservationIds.includes(itemId)) {
        await MoodleNotificationHelper.error(
            'Vous avez déjà réservé ce document !',
            'Réservation impossible'
        );
        return;
    }
    
    var allItems = [...booksData, ...thesesData];
    var item = allItems.find(i => i.name.split('/').pop() === itemId);
    
    if (!item) {
        await MoodleNotificationHelper.error(
            'Document introuvable',
            'Erreur'
        );
        return;
    }
    
    var itemName = item.fields.name ? item.fields.name.stringValue : 
                  (item.fields.Nom ? item.fields.Nom.stringValue : 'Nom non disponible');
    
    const confirmation = await MoodleNotificationHelper.confirm(
        `Vous êtes sur le point de réserver :<br><strong>"${itemName}"</strong>`,
        'Confirmation de réservation'
    );
    
    if (!confirmation.confirmed) {
        return;
    }
    
    const reservationData = {
        itemId: itemId,
        itemType: itemType,
        userDocId: userDocId
    };

    const originalText = element.textContent;
    const originalClass = element.className;
    const originalCursor = element.style.cursor;
    
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
            await MoodleNotificationHelper.success(
                'Votre réservation a été enregistrée avec succès !',
                'Réservation réussie'
            );
            
            if (!userReservationIds.includes(itemId)) {
                userReservationIds.push(itemId);
            }
            
            element.textContent = 'Réservé';
            element.classList.remove('btn-primary', 'book-btn-reserve');
            element.classList.add('btn-secondary');
            element.disabled = true;
            element.style.cursor = 'not-allowed';
            element.style.opacity = '0.6';
            
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
            
            const itemIndex = allItems.findIndex(i => i.name.split('/').pop() === itemId);
            if (itemIndex !== -1 && allItems[itemIndex].exemplaire > 0) {
                allItems[itemIndex].exemplaire--;
                
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
            await MoodleNotificationHelper.error(
                data.message || 'Une erreur est survenue lors de la réservation.',
                'Échec de la réservation'
            );
            
            element.disabled = false;
            element.textContent = originalText;
            element.style.cursor = originalCursor;
        }
        
    } catch (error) {
        console.error('Erreur lors de la réservation:', error);
        
        await MoodleNotificationHelper.error(
            'Une erreur réseau est survenue. Veuillez vérifier votre connexion et réessayer.',
            'Erreur réseau'
        );
        
        element.disabled = false;
        element.textContent = originalText;
        element.style.cursor = originalCursor;
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
    contentArea.appendChild(paginationElement);
}

// --- ÉCOUTEURS D'ÉVÉNEMENTS ---
document.addEventListener('DOMContentLoaded', (event) => {
    document.getElementById('searchBar').addEventListener('input', () => applyFilters(true));
    document.getElementById('departmentFilter').addEventListener('change', () => applyFilters(true));

    document.getElementById('switchBooks').addEventListener('click', function() {
        if (isBook) return;
        
        isBook = true;
        isThesis = false;
        
        document.getElementById('booksList').style.display = 'flex';
        document.getElementById('thesesList').style.display = 'none';
        
        this.classList.remove('btn-secondary');
        this.classList.add('btn-primary', 'active');
        
        document.getElementById('switchTheses').classList.remove('btn-primary', 'active');
        document.getElementById('switchTheses').classList.add('btn-secondary');
        
        applyFilters(true);
    });

    document.getElementById('switchTheses').addEventListener('click', function() {
        if (isThesis) return;
        
        isBook = false;
        isThesis = true;
        
        document.getElementById('thesesList').style.display = 'flex';
        document.getElementById('booksList').style.display = 'none';
        
        this.classList.remove('btn-secondary');
        this.classList.add('btn-primary', 'active');
        
        document.getElementById('switchBooks').classList.remove('btn-primary', 'active');
        document.getElementById('switchBooks').classList.add('btn-secondary');
        
        applyFilters(true);
    });

    document.getElementById('switchBooks').classList.add('active');
    document.getElementById('sortFilter').addEventListener('change', () => applyFilters(true));
    
    applyFilters(true);
    
    // *** MODIFICATION PRINCIPALE : Charger recommandations depuis l'API ***
    if (typeof loadUserRecommendations === 'function') {
        loadUserRecommendations();
    }
    
    // Initialiser le scroll après chargement des recommandations
    setTimeout(initHorizontalScroll, 500);
});

/**
 * MoodleNotificationHelper
 */
class MoodleNotificationHelper {
    static async show(message, type = 'info', title = '', options = {}) {
        const Notification = await new Promise(resolve => require(['core/notification'], resolve));
        
        const icons = {
            success: 'fa-check-circle text-success',
            error:   'fa-times-circle text-danger',
            warning: 'fa-exclamation-triangle text-warning',
            info:    'fa-info-circle text-info',
            confirm: 'fa-question-circle text-primary'
        };

        const iconHtml = `<i class="fa ${icons[type] || icons.info}" aria-hidden="true"></i> `;
        const fullTitle = title ? (iconHtml + title) : '';

        return new Promise((resolve) => {
            if (type === 'confirm') {
                Notification.confirm(
                    fullTitle || 'Confirmation',
                    message,
                    options.confirmText || 'Confirmer',
                    options.cancelText || 'Annuler',
                    (confirmed) => resolve({ confirmed })
                );
            } else {
                try {
                    if (title) {
                        Notification.alert(fullTitle, message, type);
                    } else {
                        throw 'no-title'; 
                    }
                } catch (e) {
                    Notification.addNotification({
                        message: fullTitle ? `<strong>${fullTitle}</strong><br>${message}` : message,
                        type: type,
                        announce: true
                    });
                }
                resolve({ shown: true });
            }
        });
    }

    static success(m, t) { return this.show(m, 'success', t); }
    static error(m, t)   { return this.show(m, 'error', t); }
    static info(m, t)    { return this.show(m, 'info', t); }
    static warning(m, t) { return this.show(m, 'warning', t); }
    static confirm(m, t) { return this.show(m, 'confirm', t); }
}