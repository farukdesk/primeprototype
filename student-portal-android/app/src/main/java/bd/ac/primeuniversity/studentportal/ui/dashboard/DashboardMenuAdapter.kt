package bd.ac.primeuniversity.studentportal.ui.dashboard

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.ImageView
import android.widget.TextView
import androidx.core.content.ContextCompat
import androidx.core.graphics.ColorUtils
import androidx.core.view.updatePadding
import androidx.recyclerview.widget.RecyclerView
import bd.ac.primeuniversity.studentportal.databinding.ItemDashboardMenuBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemDashboardMenuGridBinding
import bd.ac.primeuniversity.studentportal.databinding.ItemDashboardSectionBinding

/**
 * Renders the grouped dashboard launcher: section headers interleaved with
 * tappable feature entries. [onClick] fires with the selected [Feature].
 *
 * @param grid when true, feature entries use the compact 2-column card layout
 * (item_dashboard_menu_grid). Pair it with a
 * [androidx.recyclerview.widget.GridLayoutManager] whose span size lookup
 * gives headers the full width – see [isHeader].
 */
class DashboardMenuAdapter(
    private val rows: List<MenuRow>,
    private val grid: Boolean = false,
    private val onClick: (Feature) -> Unit,
) : RecyclerView.Adapter<RecyclerView.ViewHolder>() {

    override fun getItemCount(): Int = rows.size

    /** Whether [position] is a section header (spans all grid columns). */
    fun isHeader(position: Int): Boolean = rows[position] is MenuRow.Header

    override fun getItemViewType(position: Int): Int = when (rows[position]) {
        is MenuRow.Header -> TYPE_HEADER
        is MenuRow.Item -> TYPE_ITEM
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder {
        val inflater = LayoutInflater.from(parent.context)
        return when {
            viewType == TYPE_HEADER ->
                HeaderHolder(ItemDashboardSectionBinding.inflate(inflater, parent, false))
            grid -> {
                val binding = ItemDashboardMenuGridBinding.inflate(inflater, parent, false)
                ItemHolder(
                    binding.root, binding.iconContainer, binding.menuIcon,
                    binding.menuTitle, binding.menuSubtitle, binding.accentBar,
                )
            }
            else -> {
                val binding = ItemDashboardMenuBinding.inflate(inflater, parent, false)
                ItemHolder(
                    binding.root, binding.iconContainer, binding.menuIcon,
                    binding.menuTitle, binding.menuSubtitle,
                )
            }
        }
    }

    override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
        when (val row = rows[position]) {
            is MenuRow.Header -> (holder as HeaderHolder).bind(row)
            is MenuRow.Item -> (holder as ItemHolder).bind(row.feature)
        }
    }

    inner class HeaderHolder(
        private val binding: ItemDashboardSectionBinding,
    ) : RecyclerView.ViewHolder(binding.root) {
        fun bind(header: MenuRow.Header) {
            binding.sectionTitle.setText(header.titleRes)
            if (grid) {
                // Align the header's left edge exactly with the card edges
                // below it: list padding + the 6dp card margin. Trim the
                // header's built-in 20dp down to 6dp.
                val pad = (6 * binding.root.resources.displayMetrics.density).toInt()
                binding.sectionTitle.updatePadding(left = pad, right = pad)
            }
        }
    }

    inner class ItemHolder(
        root: View,
        private val iconContainer: View,
        private val menuIcon: ImageView,
        private val menuTitle: TextView,
        private val menuSubtitle: TextView,
        private val accentBar: View? = null,
    ) : RecyclerView.ViewHolder(root) {
        fun bind(feature: Feature) {
            val context = itemView.context
            menuTitle.setText(feature.titleRes)
            menuIcon.setImageResource(feature.iconRes)

            val accent = ContextCompat.getColor(context, feature.colorRes)
            menuIcon.setColorFilter(accent)
            // Solid pastel chip when the feature defines one; otherwise a
            // translucent wash of the accent colour.
            iconContainer.background?.mutate()?.setTint(
                if (feature.containerRes != 0) {
                    ContextCompat.getColor(context, feature.containerRes)
                } else {
                    ColorUtils.setAlphaComponent(accent, 40)
                }
            )
            accentBar?.setBackgroundColor(accent)

            if (feature.subtitleRes != 0) {
                menuSubtitle.setText(feature.subtitleRes)
                menuSubtitle.visibility = View.VISIBLE
            } else {
                menuSubtitle.visibility = View.GONE
            }

            itemView.setOnClickListener { onClick(feature) }
        }
    }

    companion object {
        private const val TYPE_HEADER = 0
        private const val TYPE_ITEM = 1
    }
}
