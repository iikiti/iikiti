<p align="center">
	<img src="https://iikiti.github.io/brand/logo/iikiti-logo-128.png" width="128" height="130" alt="Logo with a dark splat. Inside is a circle consisting of various spiraled slices. The slices are made of of various triangular shapes with gradient colors ranging from dark blue at the top to bright yellow at the bottom." />
</p>

# iikiti

| :exclamation:  This is a work in progress (WIP) and not ready for production use!   |
|-------------------------------------------------------------------------------------|

iikiti is a content management system with the goal of making building webpages ranging from simple blogs to online marketplaces (e-commerce) to enterprise intranets. It uses a static structure to prevent markdown abuse that plagues modern websites and provides an easy to use block editor that makes designing, building, managing, developing and publishing pages easy. Unlike most other frameworks, iikiti's editor is on-page. This makes iikiti front-end first and allows you to see what the final page will look like without having to preview it.

## Planned User Features

### Static Structure

Frameworks and web platforms today simply allow extensions to restructure every bit of the HTML document. Even some of the most well known and popular sites can have hundreds if not thousands of layers of elements, mostly divisions (\<div\>). This can been known as div soup and can reduce the performance of sites. Web browsers have done a lot to prevent performance hits from using thousands of layers of elements but even they have a limit.

In the past, the massive number of layers was required as CSS was not in a state to properly decorate & position elements to designers and customer's specification with minimal layers. Today, with CSS layer 3 largely supported and new modules on the way, the vast majority of design specifications can be met with just a couple layers.

iikiti takes a new approach: Don't allow extensions to write a custom structure. The base structure of the document is set in stone and only components add new layers of elements. Even the components are meant to keep element layers to a minimum. Extensions and themes can only modify the style of the existing structure and add custom-made components.

This restriction also makes it easier for themes to have greater support. With a static and limited structure, themes can focus on design and not building everything from scratch each time. They don't have to coordinate and try to create class naming standards to try get each theme to work with each unique structure. It allows designers to focus on designing and less on adding support for every class and structure that themes could use.

### Block Editor

Back in the day, block editors were a pipe-dream. That changed a while back with most now supporting block editors. However, many of them are difficult to use, easy to break, and frustrating to figure out for most. In addition, some come with 5 or more options of block editors and few have support for the others. All of this just adds more frustration to software that was supposed to make things easy.

iikiti comes with a minimal-struggle block editor. It is designed from the start to be easy to use, prevent common mishaps like accidentally dragging combined elements apart and not being able to undo... or not being able to drag items up a long list to the exact position you want it... or not being able to see what the end result will look like without saving, previewing, and repeating.

### HTML Components & Tags

Shortcodes and short-tags have been around for a long time, but they haven't been done correctly. They are not based on any standard and lack features such as escape characters and encoding requirements. This leaves developers with few if any choices. Even certain user content, not properly encoded or decoded, could leave people with errors and broken sites.

iikti uses HTML for it's components. It is a what websites are built on and backed by a standard... so why not just reuse it? The encoding and decoding of content, attributes, and structure is handled automatically with little chance of an issue causing a broken site.

There are also be inline dynamic tags (also handled via custom HTML tags) that will allow you to add everything from the current year or date to an value from another object.

### AI Prompt Site Building

Want to build a site just by writing a text prompt? Now you can! You can write a AI prompt defining what you would like to add and components will be added automatically based on how you describe your site layout.

### Other Features

All the usual bells & whistles. Import/export. Multiple-application & site support & management.

## Planned Developer Features

### Function & Composition

Instead of writing an entire page structure with header, footer, sidebars, etc, extension and theme developers focus on the backend, design, and components. Instead of getting in the user's way, you let them build how they would like their site to look and function. Developers focus on providing them the tools to do so.

Extension developers focus on providing middleware, services, connections, and components to provide new functionality and integration with other services and provide front-end access to it via components and administrative interfaces.

Theme developers focus on components that provide the user the ability to shape their site the way they see fit. Components can even be combined in to form composites that combine the features of multiple components into one. There is also full control over the style and color palette, including automated or manual light/dark theming tools.