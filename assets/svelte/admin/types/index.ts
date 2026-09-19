/**
 * API resource interfaces for the admin UI.
 * Mirrors the PHP {@see \iikiti\CMS\ApiResource\*} DTOs.
 */

/**
 * @typedef {Object} MenuItem
 * @property {string} label
 * @property {string} path
 * @property {string|null} [icon]
 * @property {string|null} [badge]
 * @property {MenuItem[]} [children]
 * @property {number} [priority]
 */

/**
 * @typedef {Object} PagedResult
 * @template T
 * @property {T[]} members
 * @property {number} totalItems
 * @property {number} itemsPerPage
 * @property {number} currentPage
 */

/**
 * @typedef {Object} AuditLogResource
 * @property {number} id
 * @property {number|null} userId
 * @property {string} actorType
 * @property {string} action
 * @property {string} objectType
 * @property {number|null} objectId
 * @property {Object<string,*>|null} beforeState
 * @property {Object<string,*>|null} afterState
 * @property {Object<string,*>} context
 * @property {string|null} ipAddress
 * @property {string|null} requestUri
 * @property {string} createdAt
 */

/**
 * @typedef {Object} RoleResource
 * @property {number} id
 * @property {string} name
 * @property {string} value
 * @property {boolean} isDefault
 * @property {boolean} isDeletable
 * @property {boolean} isHidden
 * @property {Object<string,string[]>} defaultPermissions
 * @property {Object<string,string[]>} customPermissions
 * @property {Object<string,string[]>} allPermissions
 */

/**
 * @typedef {Object} UserResource
 * @property {number} id
 * @property {string|null} username
 * @property {string[]|null} emails
 * @property {Object<string,string[]>} globalRoles
 * @property {Object<string,string[]>} siteRoles
 * @property {(number|string)[]|null} groupIds
 * @property {Object<string,*>|null} preferences
 * @property {number|null} creatorId
 * @property {string|null} createdDate
 * @property {string|null} updatedDate
 */

/**
 * @typedef {Object} UserGroupResource
 * @property {number|null} id
 * @property {string} name
 * @property {string} label
 * @property {string|null} description
 * @property {boolean} isSystem
 * @property {boolean} isHidden
 * @property {Object<string,Object<string,string[]>>} permissions
 * @property {number|null} userCount
 */

/**
 * @typedef {Object} SiteGroupResource
 * @property {number|null} id
 * @property {string} name
 * @property {string} label
 * @property {string|null} description
 * @property {boolean} isSystem
 * @property {(string|number)[]} siteIds
 */

export {};
